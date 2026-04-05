<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Entity\UserAccountAuditLog;
use App\Repository\UserRepository;
use App\Service\UserManagementAuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/accept-invitation')]
final class ApiAcceptInvitationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly UserManagementAuditLogger $auditLogger,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_accept_invitation', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $token = isset($payload['token']) ? trim((string) $payload['token']) : '';
        $password = isset($payload['password']) ? (string) $payload['password'] : '';

        $fields = [];
        foreach ($this->validator->validate($token, [new Assert\NotBlank(message: 'Jeton requis')]) as $v) {
            $fields['token'] = $v->getMessage();
            break;
        }
        foreach ($this->validator->validate($password, [
            new Assert\NotBlank(message: 'Mot de passe requis'),
            new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins 8 caractères'),
        ]) as $v) {
            $fields['password'] = $v->getMessage();
            break;
        }
        if ($fields !== []) {
            return new JsonResponse(['error' => 'Validation', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userRepository->findOneByInviteToken($token);
        if (!$user instanceof User || !$user->hasPendingInvite()) {
            return new JsonResponse(['error' => 'Lien d’invitation invalide ou expiré.'], Response::HTTP_BAD_REQUEST);
        }

        $expires = $user->getInviteExpiresAt();
        if ($expires instanceof \DateTimeImmutable && $expires < new \DateTimeImmutable()) {
            return new JsonResponse(['error' => 'Lien d’invitation invalide ou expiré.'], Response::HTTP_BAD_REQUEST);
        }

        $org = $user->getOrganization();
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setInviteToken(null);
        $user->setInviteExpiresAt(null);
        $user->setEmailVerified(true);

        $this->auditLogger->persist(
            UserAccountAuditLog::ACTION_INVITE_COMPLETED,
            null,
            $user,
            $org,
            $request,
        );

        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Mot de passe enregistré. Vous pouvez vous connecter.']);
    }
}
