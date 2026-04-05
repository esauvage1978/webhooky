<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hors administrateurs : sans organisation rattachée, seules /api/me, déconnexion,
 * liste organisations vide, création bootstrap et acceptation d’invitation sont autorisées.
 */
final class RequireOrganizationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 4]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();

        if (!str_starts_with($path, '/api/')) {
            return;
        }

        if ($this->security->isGranted('ROLE_ADMIN')) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        if ($user->getOrganization() !== null) {
            return;
        }

        if ($this->isAllowedWithoutOrganization($path, $request->getMethod())) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'Créez d’abord votre organisation pour accéder à cette fonctionnalité.',
            'code' => 'organization_required',
        ], 403));
    }

    private function isAllowedWithoutOrganization(string $path, string $method): bool
    {
        if (preg_match('#^/api/me$#', $path) === 1) {
            return true;
        }

        if (preg_match('#^/api/logout$#', $path) === 1) {
            return true;
        }

        if ($method === 'GET' && preg_match('#^/api/organizations/?$#', $path) === 1) {
            return true;
        }

        if ($method === 'POST' && $path === '/api/organizations/bootstrap') {
            return true;
        }

        return $method === 'POST' && $path === '/api/accept-invitation';
    }
}
