<?php

declare(strict_types=1);

namespace App\Subscription;

use App\Entity\FormWebhook;
use App\Entity\Organization;
use App\Repository\FormWebhookRepository;
use App\Repository\OrganizationRepository;

final class SubscriptionEntitlementService
{
    public function __construct(
        private readonly FormWebhookRepository $formWebhookRepository,
        private readonly OrganizationRepository $organizationRepository,
    ) {
    }

    public function isEntitledToWebhooks(Organization $organization): bool
    {
        if ($organization->getSubscriptionPlan() === SubscriptionPlan::Free) {
            return $this->isFreeTierBillingOk($organization);
        }

        return $this->isPaidSubscriptionEntitled($organization);
    }

    private function isFreeTierBillingOk(Organization $organization): bool
    {
        $st = $organization->getBillingStatus();

        return $st === BillingStatus::Active
            || $st === BillingStatus::Trialing;
    }

    private function isPaidSubscriptionEntitled(Organization $organization): bool
    {
        $st = $organization->getBillingStatus();
        $now = new \DateTimeImmutable();

        if ($st === BillingStatus::Incomplete) {
            return false;
        }

        if ($st === BillingStatus::PastDue) {
            return false;
        }

        if ($st === BillingStatus::Canceled) {
            $end = $organization->getSubscriptionCurrentPeriodEnd();

            return $end !== null && $now <= $end;
        }

        if ($st === BillingStatus::Active || $st === BillingStatus::Trialing) {
            $end = $organization->getSubscriptionCurrentPeriodEnd();
            if ($end === null) {
                return true;
            }

            return $now <= $end;
        }

        return false;
    }

    public function getTotalEventsAllowance(Organization $organization): int
    {
        $plan = $organization->getSubscriptionPlan();

        return $plan->baseEventsIncluded() + $organization->getEventsExtraQuota();
    }

    /**
     * Tentative atomique : +1 événement si sous le plafond.
     */
    public function tryConsumeOneEvent(Organization $organization): bool
    {
        return $this->tryConsumeEvents($organization, 1);
    }

    /**
     * Tentative atomique : +$count événements (une unité par action exécutée) si le plafond le permet.
     */
    public function tryConsumeEvents(Organization $organization, int $count): bool
    {
        if ($count < 1) {
            return true;
        }
        $cap = $this->getTotalEventsAllowance($organization);

        return $this->organizationRepository->incrementEventsConsumedIfBelowCap($organization, $cap, $count);
    }

    public function hasEventQuotaRemaining(Organization $organization): bool
    {
        return $organization->getEventsConsumed() < $this->getTotalEventsAllowance($organization);
    }

    public function getMaxWebhooks(Organization $organization): ?int
    {
        return $organization->getSubscriptionPlan()->maxWebhooks();
    }

    public function countWebhooks(Organization $organization): int
    {
        return $this->formWebhookRepository->countByOrganization($organization);
    }

    public function countWebhooksExcluding(Organization $organization, ?FormWebhook $exclude): int
    {
        return $this->formWebhookRepository->countByOrganizationExcludingWebhook($organization, $exclude);
    }

    public function canCreateWebhook(Organization $organization): bool
    {
        if (!$this->isEntitledToWebhooks($organization)) {
            return false;
        }

        $max = $this->getMaxWebhooks($organization);
        if ($max === null) {
            return true;
        }

        return $this->countWebhooks($organization) < $max;
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshot(Organization $organization): array
    {
        $plan = $organization->getSubscriptionPlan();
        $max = $this->getMaxWebhooks($organization);
        $wCount = $this->countWebhooks($organization);
        $entitled = $this->isEntitledToWebhooks($organization);
        $canCreate = $this->canCreateWebhook($organization);
        $allowance = $this->getTotalEventsAllowance($organization);
        $eConsumed = $organization->getEventsConsumed();
        $eRemaining = max(0, $allowance - $eConsumed);
        $eventQuotaBlocked = $eConsumed >= $allowance;
        $allowEventOverage = $plan !== SubscriptionPlan::Free;

        return [
            'plan' => $plan->value,
            'planLabel' => $plan->label(),
            'trialEndsAt' => $organization->getTrialEndsAt()?->format(\DateTimeInterface::ATOM),
            'billingStatus' => $organization->getBillingStatus()->value,
            'billingStatusLabel' => $organization->getBillingStatus()->label(),
            'currentPeriodEnd' => $organization->getSubscriptionCurrentPeriodEnd()?->format(\DateTimeInterface::ATOM),
            'maxWebhooks' => $max,
            'webhookCount' => $wCount,
            'canCreateWebhook' => $canCreate,
            'webhooksOperational' => $entitled && !$eventQuotaBlocked,
            'eventsConsumed' => $eConsumed,
            'eventsIncluded' => $plan->baseEventsIncluded(),
            'eventsExtraQuota' => $organization->getEventsExtraQuota(),
            'eventsAllowance' => $allowance,
            'eventsRemaining' => $eRemaining,
            'allowEventOverage' => $allowEventOverage,
            'blockReason' => $this->describeBlockReason(
                $organization,
                $entitled,
                $canCreate,
                $max,
                $wCount,
                $eventQuotaBlocked,
                $allowEventOverage,
            ),
        ];
    }

    private function describeBlockReason(
        Organization $organization,
        bool $entitled,
        bool $canCreate,
        ?int $max,
        int $wCount,
        bool $eventQuotaBlocked,
        bool $allowEventOverage,
    ): ?string {
        if (!$entitled) {
            if ($organization->getBillingStatus() === BillingStatus::PastDue) {
                return 'Abonnement en retard de paiement. Régularisez la facturation pour réactiver le service.';
            }
            if ($organization->getBillingStatus() === BillingStatus::Incomplete) {
                return 'Abonnement incomplet : finalisez le paiement.';
            }
            if ($organization->getBillingStatus() === BillingStatus::Canceled) {
                return 'Abonnement résilié ou période payée expirée.';
            }

            return 'Abonnement inactif.';
        }

        if ($eventQuotaBlocked) {
            if ($allowEventOverage) {
                return 'Quota d’événements épuisé. Achetez un pack d’événements ou changez de forfait.';
            }

            return 'Quota Free épuisé (100 événements). Passez à Starter ou Pro pour continuer.';
        }

        if (!$canCreate && $max !== null && $wCount >= $max) {
            return 'Nombre maximal de webhooks atteint pour ce forfait. Passez à Starter ou Pro.';
        }

        return null;
    }
}
