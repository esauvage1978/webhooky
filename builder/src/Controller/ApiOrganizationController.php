<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\OrganizationRepository;
use App\Repository\UserRepository;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/organizations')]
final class ApiOrganizationController extends AbstractController
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepository,
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
        private readonly SubscriptionEntitlementService $subscriptionEntitlement,
    ) {
    }

    #[Route('', name: 'api_organizations_list', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function list(): JsonResponse
    {
        $user = $this->currentUser();
        if ($this->isAdmin($user)) {
            $orgs = $this->organizationRepository->findByNameAsc();
            $payload = array_map(fn (Organization $o) => $this->serializeOrganization($o, true), $orgs);

            return new JsonResponse($payload);
        }

        $org = $user->getOrganization();
        if ($org === null) {
            return new JsonResponse([]);
        }

        return new JsonResponse([$this->serializeOrganization($org, true)]);
    }

    #[Route('/{id}', name: 'api_organizations_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(int $id): JsonResponse
    {
        $organization = $this->organizationRepository->find($id);
        if (!$organization instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccess($user, $organization)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        return new JsonResponse($this->serializeOrganization($organization, true));
    }

    #[Route('/bootstrap', name: 'api_organizations_bootstrap', methods: ['POST'], priority: 10)]
    #[IsGranted('ROLE_USER')]
    public function bootstrapMyOrganization(Request $request): JsonResponse
    {
        $user = $this->currentUser();
        if ($this->isAdmin($user)) {
            return new JsonResponse(
                ['error' => 'Les administrateurs utilisent la création d’organisation dans l’écran dédié.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if ($user->getOrganization() !== null) {
            return new JsonResponse(
                ['error' => 'Votre compte est déjà rattaché à une organisation.'],
                Response::HTTP_CONFLICT,
            );
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $organization = (new Organization())->setName($name);

        $errors = $this->validator->validate($organization);
        if (\count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        $this->entityManager->persist($organization);
        $this->attachUserToOrganization($user, $organization);
        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeOrganization($organization, true),
            Response::HTTP_CREATED,
        );
    }

    #[Route('', name: 'api_organizations_create', methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function create(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $name = trim((string) ($data['name'] ?? ''));
        $organization = (new Organization())->setName($name);

        $errors = $this->validator->validate($organization);
        if (\count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        $this->entityManager->persist($organization);

        if (isset($data['userId']) && $data['userId'] !== '' && $data['userId'] !== null) {
            $targetUser = $this->userRepository->find((int) $data['userId']);
            if (!$targetUser instanceof User) {
                return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_BAD_REQUEST);
            }
            $this->attachUserToOrganization($targetUser, $organization);
        }

        $this->entityManager->flush();

        return new JsonResponse(
            $this->serializeOrganization($organization, true),
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_organizations_update', methods: ['PUT'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function update(int $id, Request $request): JsonResponse
    {
        $organization = $this->organizationRepository->find($id);
        if (!$organization instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $user = $this->currentUser();
        if (!$this->canAccess($user, $organization)) {
            return new JsonResponse(['error' => 'Accès refusé'], Response::HTTP_FORBIDDEN);
        }

        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        if (\array_key_exists('name', $data)) {
            $organization->setName(trim((string) $data['name']));
        }

        $errors = $this->validator->validate($organization);
        if (\count($errors) > 0) {
            return $this->validationErrorResponse($errors);
        }

        if ($this->isAdmin($user) && \array_key_exists('userId', $data)) {
            $userId = $data['userId'];
            if ($userId === null || $userId === '') {
                $this->detachAllUsersFromOrganization($organization);
            } else {
                $targetUser = $this->userRepository->find((int) $userId);
                if (!$targetUser instanceof User) {
                    return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_BAD_REQUEST);
                }
                $this->attachUserToOrganization($targetUser, $organization);
            }
        }

        $this->entityManager->flush();

        return new JsonResponse($this->serializeOrganization($organization, true));
    }

    #[Route('/{id}', name: 'api_organizations_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(int $id): JsonResponse
    {
        $organization = $this->organizationRepository->find($id);
        if (!$organization instanceof Organization) {
            return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_NOT_FOUND);
        }

        $this->detachAllUsersFromOrganization($organization);
        $this->entityManager->remove($organization);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
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

    private function canAccess(User $user, Organization $organization): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $user->getOrganization()?->getId() === $organization->getId();
    }

    private function attachUserToOrganization(User $user, Organization $organization): void
    {
        $user->setOrganization($organization);
    }

    private function detachAllUsersFromOrganization(Organization $organization): void
    {
        foreach ($this->userRepository->findBy(['organization' => $organization]) as $u) {
            $u->setOrganization(null);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeOrganization(Organization $organization, bool $includeMembers): array
    {
        $row = [
            'id' => $organization->getId(),
            'name' => $organization->getName(),
        ];

        $members = $this->userRepository->findByOrganizationOrderedByEmail($organization);
        $row['memberCount'] = \count($members);

        if ($includeMembers) {
            $row['members'] = array_map(static function (User $u) {
                return [
                    'id' => $u->getId(),
                    'email' => $u->getEmail(),
                    'roles' => $u->getRoles(),
                    'accountEnabled' => $u->isAccountEnabled(),
                    'invitePending' => $u->hasPendingInvite(),
                ];
            }, $members);
        }

        $row['subscription'] = $this->subscriptionEntitlement->buildSnapshot($organization);

        return $row;
    }

    private function validationErrorResponse(ConstraintViolationListInterface $errors): JsonResponse
    {
        $messages = [];
        foreach ($errors as $error) {
            $messages[$error->getPropertyPath()] = $error->getMessage();
        }

        return new JsonResponse(['error' => 'Validation', 'fields' => $messages], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
