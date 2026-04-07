<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Organization;
use App\Entity\User;
use App\Entity\UserAccountAuditLog;
use App\Repository\MailjetRepository;
use App\Repository\OrganizationRepository;
use App\Repository\UserAccountAuditLogRepository;
use App\Repository\UserRepository;
use App\Service\AuthMailer;
use App\Service\UserManagementAuditLogger;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException as DbalUniqueConstraintViolationException;
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

#[Route('/api/users')]
#[IsGranted('ROLE_USER')]
final class ApiUserController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly OrganizationRepository $organizationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuthMailer $authMailer,
        private readonly UserManagementAuditLogger $auditLogger,
        private readonly MailjetRepository $mailjetRepository,
        private readonly UserAccountAuditLogRepository $auditLogRepository,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_users_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        $this->ensureManagerOrAdmin($actor);

        if ($this->isAdmin($actor)) {
            $orgId = $request->query->get('organizationId');
            if ($orgId !== null && $orgId !== '') {
                $org = $this->organizationRepository->find((int) $orgId);
                if (!$org instanceof Organization) {
                    return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
                }
                $users = $this->userRepository->findByOrganizationOrderedByEmail($org);
            } else {
                $users = $this->userRepository->findAllOrderedByEmail();
            }
        } else {
            $org = $actor->getOrganization();
            if ($org === null) {
                return new JsonResponse(['error' => 'Organisation requise'], Response::HTTP_FORBIDDEN);
            }
            $users = $this->userRepository->findByOrganizationOrderedByEmail($org);
        }

        return new JsonResponse(array_map(fn (User $u) => $this->serializeUser($u), $users));
    }

    #[Route('/audit-logs', name: 'api_users_audit_logs', methods: ['GET'])]
    public function auditLogs(Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        $this->ensureManagerOrAdmin($actor);

        if ($this->isAdmin($actor)) {
            $orgId = $request->query->get('organizationId');
            if ($orgId !== null && $orgId !== '') {
                $org = $this->organizationRepository->find((int) $orgId);
                if (!$org instanceof Organization) {
                    return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
                }
                $logs = $this->auditLogRepository->findRecentForOrganization($org, 200);
            } else {
                $logs = $this->auditLogRepository->findRecentAll(300);
            }
        } else {
            $org = $actor->getOrganization();
            if ($org === null) {
                return new JsonResponse(['error' => 'Organisation requise'], Response::HTTP_FORBIDDEN);
            }
            $logs = $this->auditLogRepository->findRecentForOrganization($org, 200);
        }

        return new JsonResponse(array_map(fn ($log) => $this->serializeAuditLog($log), $logs));
    }

    #[Route('', name: 'api_users_invite', methods: ['POST'])]
    public function invite(Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        $this->ensureManagerOrAdmin($actor);

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $email = isset($payload['email']) ? mb_strtolower(trim((string) $payload['email'])) : '';

        $fields = [];
        foreach ($this->validator->validate($email, [
            new Assert\NotBlank(message: 'E-mail requis'),
            new Assert\Email(message: 'E-mail invalide'),
        ]) as $v) {
            $fields['email'] = $v->getMessage();
            break;
        }
        if ($fields !== []) {
            return new JsonResponse(['error' => 'Validation', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $organization = $this->resolveTargetOrganizationForInvite($actor, $payload);
        if (!$organization instanceof Organization) {
            return $organization;
        }

        $existing = $this->userRepository->findOneBy(['email' => $email]);
        if ($existing instanceof User) {
            if ($existing->hasPendingInvite()) {
                if ($existing->hasMembershipInOrganization($organization)
                    && $existing->getOrganization()?->getId() === $organization->getId()) {
                    return $this->sendFreshInvite($existing, $actor, $organization, $request);
                }

                return new JsonResponse(
                    ['error' => 'Une invitation est déjà en cours pour ce compte.'],
                    Response::HTTP_CONFLICT,
                );
            }

            if ($existing->hasMembershipInOrganization($organization)) {
                return new JsonResponse(
                    ['error' => 'Ce compte est déjà membre de cette organisation.'],
                    Response::HTTP_CONFLICT,
                );
            }

            $existing->addOrganizationMembership($organization);
            $this->entityManager->flush();

            return new JsonResponse($this->serializeUser($existing));
        }

        $user = (new User())
            ->setEmail($email)
            ->setRoles([])
            ->setEmailVerified(false)
            ->setAccountEnabled(true)
            ->setOrganization($organization);
        $user->addOrganizationMembership($organization);

        $dummy = bin2hex(random_bytes(32));
        $user->setPassword($this->passwordHasher->hashPassword($user, $dummy));

        $plainInvite = bin2hex(random_bytes(32));
        $user->setInviteToken($plainInvite);
        $user->setInviteExpiresAt(new \DateTimeImmutable('+14 days'));

        $this->auditLogger->persist(
            UserAccountAuditLog::ACTION_USER_INVITED,
            $actor,
            $user,
            $organization,
            $request,
        );

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (DbalUniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'Un compte existe déjà avec cette adresse e-mail.'], Response::HTTP_CONFLICT);
        }

        try {
            $this->authMailer->sendUserInvitation($user, $plainInvite);
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => 'Utilisateur créé mais l’e-mail d’invitation n’a pas pu être envoyé. Réessayez plus tard ou régénérez l’invitation.'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse($this->serializeUser($user), Response::HTTP_CREATED);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolveTargetOrganizationForInvite(User $actor, array $payload): Organization|JsonResponse
    {
        if ($this->isAdmin($actor)) {
            if (!isset($payload['organizationId']) || $payload['organizationId'] === '' || $payload['organizationId'] === null) {
                return new JsonResponse(['error' => 'organizationId requis pour un administrateur.'], Response::HTTP_BAD_REQUEST);
            }
            $org = $this->organizationRepository->find((int) $payload['organizationId']);
            if (!$org instanceof Organization) {
                return new JsonResponse(['error' => 'Organisation introuvable'], Response::HTTP_BAD_REQUEST);
            }

            return $org;
        }

        $org = $actor->getOrganization();
        if ($org === null) {
            return new JsonResponse(['error' => 'Organisation requise'], Response::HTTP_FORBIDDEN);
        }

        return $org;
    }

    private function sendFreshInvite(User $user, User $actor, Organization $organization, Request $request): JsonResponse
    {
        $plainInvite = bin2hex(random_bytes(32));
        $user->setInviteToken($plainInvite);
        $user->setInviteExpiresAt(new \DateTimeImmutable('+14 days'));

        $this->auditLogger->persist(
            UserAccountAuditLog::ACTION_USER_INVITED,
            $actor,
            $user,
            $organization,
            $request,
            ['resent' => true],
        );

        $this->entityManager->flush();

        try {
            $this->authMailer->sendUserInvitation($user, $plainInvite);
        } catch (\Throwable) {
            return new JsonResponse(
                ['error' => 'Jeton renouvelé mais l’e-mail n’a pas pu être envoyé.'],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse($this->serializeUser($user));
    }

    #[Route('/{id}', name: 'api_users_patch', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function patch(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        $this->ensureManagerOrAdmin($actor);

        $target = $this->userRepository->find($id);
        if (!$target instanceof User) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canModifyUser($actor, $target)) {
            return new JsonResponse(['error' => 'Action non autorisée sur ce compte.'], Response::HTTP_FORBIDDEN);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload) || !\array_key_exists('accountEnabled', $payload)) {
            return new JsonResponse(['error' => 'Champ accountEnabled requis.'], Response::HTTP_BAD_REQUEST);
        }

        $enabled = filter_var($payload['accountEnabled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($enabled === null) {
            return new JsonResponse(['error' => 'accountEnabled invalide.'], Response::HTTP_BAD_REQUEST);
        }

        if ($target->getId() === $actor->getId()) {
            return new JsonResponse(['error' => 'Vous ne pouvez pas modifier votre propre accès ainsi.'], Response::HTTP_FORBIDDEN);
        }

        $wasEnabled = $target->isAccountEnabled();
        if ($wasEnabled === $enabled) {
            return new JsonResponse($this->serializeUser($target));
        }

        $target->setAccountEnabled($enabled);
        $action = $enabled ? UserAccountAuditLog::ACTION_USER_UNBLOCKED : UserAccountAuditLog::ACTION_USER_BLOCKED;
        $this->auditLogger->persist(
            $action,
            $actor,
            $target,
            $target->getOrganization(),
            $request,
        );

        $this->entityManager->flush();

        return new JsonResponse($this->serializeUser($target));
    }

    #[Route('/{id}', name: 'api_users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $actor = $this->currentUser();
        $this->ensureManagerOrAdmin($actor);

        $target = $this->userRepository->find($id);
        if (!$target instanceof User) {
            return new JsonResponse(['error' => 'Utilisateur introuvable'], Response::HTTP_NOT_FOUND);
        }

        if (!$this->canModifyUser($actor, $target)) {
            return new JsonResponse(['error' => 'Action non autorisée sur ce compte.'], Response::HTTP_FORBIDDEN);
        }

        if ($target->getId() === $actor->getId()) {
            return new JsonResponse(['error' => 'Vous ne pouvez pas supprimer votre propre compte ainsi.'], Response::HTTP_FORBIDDEN);
        }

        if ($this->mailjetRepository->countCreatedBy($target) > 0) {
            return new JsonResponse([
                'error' => 'Impossible de supprimer cet utilisateur : des configurations Mailjet lui sont rattachées.',
            ], Response::HTTP_CONFLICT);
        }

        $this->auditLogger->persist(
            UserAccountAuditLog::ACTION_USER_DELETED,
            $actor,
            $target,
            $target->getOrganization(),
            $request,
        );

        $this->entityManager->remove($target);
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

    private function ensureManagerOrAdmin(User $user): void
    {
        if ($this->isAdmin($user) || $user->isAppManager()) {
            return;
        }

        throw $this->createAccessDeniedException('Accès réservé aux gestionnaires et administrateurs.');
    }

    private function canModifyUser(User $actor, User $target): bool
    {
        if ($this->isAdmin($actor)) {
            return true;
        }

        if (!$actor->isAppManager()) {
            return false;
        }

        if (!$target->isOrgMember()) {
            return false;
        }

        $actorOrg = $actor->getOrganization();

        return $actorOrg !== null && $target->hasMembershipInOrganization($actorOrg);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUser(User $user): array
    {
        $org = $user->getOrganization();

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'accountEnabled' => $user->isAccountEnabled(),
            'invitePending' => $user->hasPendingInvite(),
            'createdAt' => $user->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'lastLoginAt' => $user->getLastLoginAt()?->format(\DateTimeInterface::ATOM),
            'organization' => $org !== null
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAuditLog(UserAccountAuditLog $log): array
    {
        $actor = $log->getActorUser();
        $target = $log->getTargetUser();
        $org = $log->getOrganization();

        return [
            'id' => $log->getId(),
            'occurredAt' => $log->getOccurredAt()->format(\DateTimeInterface::ATOM),
            'action' => $log->getAction(),
            'actor' => $actor instanceof User
                ? ['id' => $actor->getId(), 'email' => $actor->getEmail()]
                : null,
            'targetEmail' => $log->getTargetEmail(),
            'targetUserId' => $target instanceof User ? $target->getId() : null,
            'organization' => $org instanceof Organization
                ? ['id' => $org->getId(), 'name' => $org->getName()]
                : null,
            'details' => $log->getDetails(),
            'clientIp' => $log->getClientIp(),
        ];
    }
}
