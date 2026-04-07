<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Onboarding\UserOnboardingEvaluator;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tant que l’onboarding obligatoire n’est pas terminé, seules les routes utiles au parcours sont autorisées.
 */
final class RequireCompleteOnboardingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly UserOnboardingEvaluator $onboardingEvaluator,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 24]];
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

        if (!$this->onboardingEvaluator->needsOnboarding($user)) {
            return;
        }

        if ($this->isAllowedDuringOnboarding($path, $request->getMethod())) {
            return;
        }

        $event->setResponse(new JsonResponse([
            'error' => 'Terminez d’abord le parcours d’accueil obligatoire.',
            'code' => 'onboarding_required',
            'onboarding' => [
                'currentStep' => $this->onboardingEvaluator->currentStep($user),
                'steps' => $this->onboardingEvaluator->pendingSteps($user),
            ],
        ], 403));
    }

    private function isAllowedDuringOnboarding(string $path, string $method): bool
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

        if ($method === 'POST' && $path === '/api/me/active-organization') {
            return true;
        }

        if ($method === 'POST' && $path === '/api/organizations/bootstrap') {
            return true;
        }

        if ($method === 'POST' && $path === '/api/accept-invitation') {
            return true;
        }

        if ($method === 'POST' && $path === '/api/onboarding/profile') {
            return true;
        }

        if ($method === 'POST' && $path === '/api/onboarding/plan') {
            return true;
        }

        if ($method === 'GET' && $path === '/api/subscription/plans') {
            return true;
        }

        return false;
    }
}
