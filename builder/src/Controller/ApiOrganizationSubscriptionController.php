<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Subscription\BillingStatus;
use App\Subscription\SubscriptionEntitlementService;
use App\Subscription\SubscriptionPlan;
use App\Subscription\SubscriptionPlanCatalog;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/organizations')]
#[IsGranted('ROLE_USER')]
final class ApiOrganizationSubscriptionController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly SubscriptionEntitlementService $entitlementService,
    ) {
    }

    #[Route('/{id}/subscription', name: 'api_organizations_subscription_patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patchSubscription(int $id, Request $request): JsonResponse
    {
        $organization = $this->organizationRepository->find($id);
        if (!$organization instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $actor = $this->currentUser();
        if (!$this->canManageSubscription($actor, $organization)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        if ($organization->isSubscriptionExempt()) {
            return new JsonResponse(
                [
                    'error' => 'Cette organisation est interne et hors forfait : les changements de plan ou de packs ne s’appliquent pas.',
                    'code' => 'organization_subscription_exempt',
                ],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (isset($payload['plan'])) {
            $plan = SubscriptionPlan::tryFrom((string) $payload['plan']);
            if ($plan === null) {
                return new JsonResponse(['error' => 'Forfait inconnu (free, starter, pro).'], Response::HTTP_BAD_REQUEST);
            }

            if ($plan === SubscriptionPlan::Free) {
                if (!$this->isAdmin($actor)) {
                    return new JsonResponse(['error' => 'Seul un administrateur peut repasser une organisation en forfait Free.'], Response::HTTP_FORBIDDEN);
                }
                $wCount = $this->entitlementService->countWebhooks($organization);
                if ($wCount > 1) {
                    return new JsonResponse([
                        'error' => 'Le forfait Free autorise un seul webhook. Supprimez des webhooks avant de rétrograder.',
                        'code' => 'webhook_limit_conflict',
                        'webhookCount' => $wCount,
                    ], Response::HTTP_CONFLICT);
                }
                $clearUsage = filter_var($payload['clearEventUsage'] ?? false, FILTER_VALIDATE_BOOLEAN);
                $organization->applyAdminResetToFree($clearUsage);
                $this->syncStripeIdsFromPayload($organization, $payload, true);
            } else {
                $count = $this->entitlementService->countWebhooks($organization);
                $max = $plan->maxWebhooks();
                if ($max !== null && $count > $max) {
                    return new JsonResponse([
                        'error' => 'Ce forfait autorise au plus '.$max.' webhook(s). Supprimez des webhooks avant de souscrire.',
                        'code' => 'webhook_limit_conflict',
                        'webhookCount' => $count,
                    ], Response::HTTP_CONFLICT);
                }

                $newAllowance = $plan->baseEventsIncluded() + $organization->getEventsExtraQuota();
                if ($organization->getEventsConsumed() > $newAllowance) {
                    return new JsonResponse([
                        'error' => 'L’usage événements dépasse le quota du forfait cible (inclus + packs). Ajustez les packs ou attendez une remise à zéro de période.',
                        'code' => 'events_quota_conflict',
                        'eventsConsumed' => $organization->getEventsConsumed(),
                        'targetAllowance' => $newAllowance,
                    ], Response::HTTP_CONFLICT);
                }

                $periodEnd = null;
                if (isset($payload['currentPeriodEnd']) && \is_string($payload['currentPeriodEnd']) && $payload['currentPeriodEnd'] !== '') {
                    $periodEnd = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $payload['currentPeriodEnd'])
                        ?: new \DateTimeImmutable($payload['currentPeriodEnd']);
                }

                $organization->applyPaidPlan($plan, $periodEnd);

                if (isset($payload['billingStatus']) && $this->isAdmin($actor)) {
                    $st = BillingStatus::tryFrom((string) $payload['billingStatus']);
                    if ($st !== null) {
                        $organization->setBillingStatus($st);
                    }
                }

                $this->syncStripeIdsFromPayload($organization, $payload, false);
            }
        }

        if (isset($payload['purchaseEventPack'])) {
            $packId = (string) $payload['purchaseEventPack'];
            $resolved = SubscriptionPlanCatalog::resolveEventPack($packId);
            if ($resolved === null) {
                return new JsonResponse(['error' => 'Pack d’événements inconnu.'], Response::HTTP_BAD_REQUEST);
            }
            if ($organization->getSubscriptionPlan() !== $resolved['forPlan']) {
                return new JsonResponse(['error' => 'Ce pack ne correspond pas à votre forfait actuel.'], Response::HTTP_BAD_REQUEST);
            }
            $organization->setEventsExtraQuota($organization->getEventsExtraQuota() + $resolved['eventsAdded']);
        }

        if (!isset($payload['plan']) && !isset($payload['purchaseEventPack'])) {
            return new JsonResponse(['error' => 'Indiquez « plan » et/ou « purchaseEventPack ».'], Response::HTTP_BAD_REQUEST);
        }

        $this->entityManager->flush();

        return new JsonResponse($this->entitlementService->buildSnapshot($organization));
    }

    private function syncStripeIdsFromPayload(Organization $organization, array $payload, bool $trialMode): void
    {
        if (!isset($payload['stripeCustomerId']) && !isset($payload['stripeSubscriptionId'])) {
            return;
        }

        $user = $this->getUser();
        if (!$user instanceof User || !$this->isAdmin($user)) {
            return;
        }

        if (isset($payload['stripeCustomerId'])) {
            $v = $payload['stripeCustomerId'];
            $organization->setStripeCustomerId(\is_string($v) && $v !== '' ? $v : null);
        }
        if (isset($payload['stripeSubscriptionId']) && !$trialMode) {
            $v = $payload['stripeSubscriptionId'];
            $organization->setStripeSubscriptionId(\is_string($v) && $v !== '' ? $v : null);
        }
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException('Utilisateur attendu.');
        }

        return $user;
    }

    private function isAdmin(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    private function canManageSubscription(User $user, Organization $organization): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        if (!$user->isAppManager()) {
            return false;
        }

        return $user->getOrganization()?->getId() === $organization->getId();
    }
}
