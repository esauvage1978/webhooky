<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Entity\User;

/**
 * Étapes obligatoires avant l’accès au reste de l’application (hors admin).
 */
final class UserOnboardingEvaluator
{
    /**
     * @return list<string>
     */
    public function pendingSteps(User $user): array
    {
        if ($user->isAppAdmin()) {
            return [];
        }

        if ($user->isAppManager()) {
            if (!$user->hasAnyOrganizationMembership()) {
                return ['create_organization'];
            }
            if (!$user->isProfileOnboardingComplete()) {
                return ['profile'];
            }
            if (!$user->isPlanOnboardingComplete()) {
                return ['plan'];
            }

            return [];
        }

        if (!$user->hasAnyOrganizationMembership()) {
            return [];
        }

        if (!$user->isProfileOnboardingComplete()) {
            return ['profile'];
        }

        return [];
    }

    public function needsOnboarding(User $user): bool
    {
        return $this->pendingSteps($user) !== [];
    }

    public function currentStep(User $user): ?string
    {
        $steps = $this->pendingSteps($user);

        return $steps[0] ?? null;
    }
}
