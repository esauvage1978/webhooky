<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

final class LoginSuccessHandler implements AuthenticationSuccessHandlerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): ?Response
    {
        $user = $token->getUser();
        if ($user instanceof User) {
            $user->setLastLoginAt(new \DateTimeImmutable());
            $this->entityManager->flush();
        }

        return new JsonResponse([
            'email' => $user->getUserIdentifier(),
            'roles' => $user->getRoles(),
        ]);
    }
}
