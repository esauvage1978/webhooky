<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Contrôle post-authentification : le mot de passe a déjà été validé (pas de fuite d’info avant).
 */
final class VerifiedUserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user): void
    {
        if (!$user instanceof User) {
            return;
        }

        if (!$user->isAccountEnabled()) {
            throw new CustomUserMessageAccountStatusException(
                'Votre accès a été désactivé. Contactez un gestionnaire ou un administrateur.',
            );
        }

        if ($user->hasPendingInvite()) {
            throw new CustomUserMessageAccountStatusException(
                'Finalisez d’abord votre invitation : utilisez le lien reçu par e-mail pour définir votre mot de passe.',
            );
        }

        if (!$user->isEmailVerified()) {
            throw new CustomUserMessageAccountStatusException(
                'Veuillez confirmer votre adresse e-mail avant de vous connecter (lien envoyé à l’inscription).',
            );
        }
    }
}
