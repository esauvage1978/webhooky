<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Onboarding\UserOnboardingEvaluator;
use App\Repository\OrganizationRepository;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ApiMeController extends AbstractController
{
    public function __construct(
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserOnboardingEvaluator $onboardingEvaluator,
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return new JsonResponse(null);
        }

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

    #[Route('/api/me/profile', name: 'api_me_profile', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $displayName = isset($data['displayName']) ? trim((string) $data['displayName']) : '';

        $fields = [];
        foreach ($this->validator->validate($displayName, [
            new Assert\NotBlank(message: 'Nom d’affichage requis'),
            new Assert\Length(max: 120, maxMessage: 'Trop long'),
        ]) as $v) {
            $fields['displayName'] = $v->getMessage();
            break;
        }
        if ($fields !== []) {
            return new JsonResponse(['error' => 'Validation', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setDisplayName($displayName);
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/api/me/change-password', name: 'api_me_change_password', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function changePassword(Request $request): JsonResponse
    {
        $user = $this->requireUser();
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $current = isset($data['currentPassword']) ? (string) $data['currentPassword'] : '';
        $newPassword = isset($data['newPassword']) ? (string) $data['newPassword'] : '';

        $fields = [];

        if ($current === '') {
            $fields['currentPassword'] = 'Mot de passe actuel requis';
        } elseif (!$this->passwordHasher->isPasswordValid($user, $current)) {
            $fields['currentPassword'] = 'Mot de passe actuel incorrect';
        }

        foreach ($this->validator->validate($newPassword, [
            new Assert\NotBlank(message: 'Nouveau mot de passe requis'),
            new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins 8 caractères'),
        ]) as $v) {
            $fields['newPassword'] = $v->getMessage();
            break;
        }

        if ($fields !== []) {
            return new JsonResponse(['error' => 'Validation', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($current === $newPassword) {
            return new JsonResponse([
                'error' => 'Validation',
                'fields' => ['newPassword' => 'Le nouveau mot de passe doit être différent de l’actuel'],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $newPassword));
        $this->entityManager->flush();

        return new JsonResponse(['ok' => true]);
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
        if ($org instanceof Organization && $org->getId() !== null) {
            $ids = array_column($organizations, 'id');
            if (!\in_array($org->getId(), $ids, true)) {
                $organizations[] = [
                    'id' => $org->getId(),
                    'name' => $org->getName(),
                ];
                usort($organizations, static fn (array $a, array $b) => strcasecmp((string) $a['name'], (string) $b['name']));
            }
        }
        $pending = $this->onboardingEvaluator->pendingSteps($user);
        $email = $user->getEmail();
        if ('' === $email) {
            $email = $user->getUserIdentifier();
        }
        $row = [
            'id' => $user->getId(),
            'email' => $email,
            'displayName' => $user->getDisplayName(),
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
            ],
        ];

        if ($org !== null) {
            $row['subscription'] = $this->subscriptionEntitlement->buildSnapshot($org);
        }

        return $row;
    }
}
