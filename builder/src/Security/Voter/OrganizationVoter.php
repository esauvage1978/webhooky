<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Organization;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter pilote pour l’accès aux organisations (remplacement progressif des canAccess* dispersés).
 *
 * Attributs : ORG_VIEW, ORG_MANAGE
 */
final class OrganizationVoter extends Voter
{
    public const VIEW = 'ORG_VIEW';
    public const MANAGE = 'ORG_MANAGE';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::MANAGE], true)
            && $subject instanceof Organization;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        /** @var Organization $organization */
        $organization = $subject;

        $member = $user->hasMembershipInOrganization($organization)
            || $user->getOrganization()?->getId() === $organization->getId();

        if (!$member) {
            return false;
        }

        return match ($attribute) {
            self::VIEW => true,
            self::MANAGE => \in_array('ROLE_MANAGER', $user->getRoles(), true)
                || \in_array('ROLE_ADMIN', $user->getRoles(), true),
            default => false,
        };
    }
}
