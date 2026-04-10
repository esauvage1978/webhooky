<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Entity\User;

/**
 * Étapes obligatoires avant l’accès au reste de l’application (y compris administrateurs : même parcours que gestionnaire).
 */
final class UserOnboardingEvaluator
{
    /**
     * @return list<string>
     */
    public function pendingSteps(User $user): array
    {
        if ($user->isAppAdmin() || $user->isAppManager()) {
            if (!$user->isAttachedToAnOrganization()) {
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

        if (!$user->isAttachedToAnOrganization()) {
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
