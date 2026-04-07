<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Onboarding\ProfileAvatarCatalog;
use App\Onboarding\UserOnboardingEvaluator;
use App\Repository\OrganizationRepository;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ApiMeController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserOnboardingEvaluator $onboardingEvaluator,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function me(): JsonResponse
    {
        $user = $this->requireUser();

        return new JsonResponse($this->buildMePayload($user));
    }

    #[Route('/api/me/active-organization', name: 'api_me_active_organization', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function setActiveOrganization(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $rawId = $data['organizationId'] ?? null;
        if ($rawId === null || $rawId === '') {
            return new JsonResponse(['error' => 'organizationId requis'], Response::HTTP_BAD_REQUEST);
        }

        $organization = $this->organizationRepository->find((int) $rawId);
        if (!$organization instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_NOT_FOUND);
        }

        if (!$user->hasMembershipInOrganization($organization)) {
            return new JsonResponse(['error' => 'Vous n’êtes pas membre de cette organisation.'], Response::HTTP_FORBIDDEN);
        }

        $user->setOrganization($organization);
        $this->entityManager->flush();

        return new JsonResponse($this->buildMePayload($user));
    }

    private function requireUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw new \LogicException();
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMePayload(User $user): array
    {
        $memberOrgs = $user->getMemberOrganizations();
        $organizations = array_map(static fn (Organization $o) => [
            'id' => $o->getId(),
            'name' => $o->getName(),
        ], $memberOrgs);

        $org = $user->getOrganization();
        $pending = $this->onboardingEvaluator->pendingSteps($user);
        $row = [
            'email' => $user->getUserIdentifier(),
            'displayName' => $user->getDisplayName(),
            'avatarKey' => $user->getAvatarKey(),
            'roles' => $user->getRoles(),
            'accountEnabled' => $user->isAccountEnabled(),
            'invitePending' => $user->hasPendingInvite(),
            'organizations' => $organizations,
            'organization' => $org !== null
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
            'onboarding' => [
                'required' => $pending !== [],
                'steps' => $pending,
                'currentStep' => $pending[0] ?? null,
                'avatarOptions' => ProfileAvatarCatalog::all(),
            ],
        ];

        if ($org !== null) {
            $row['subscription'] = $this->subscriptionEntitlement->buildSnapshot($org);
        }

        return $row;
    }
}
