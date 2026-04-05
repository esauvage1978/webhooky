<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use App\Service\AuthMailer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
final class ApiPasswordResetController extends AbstractController
{
    private const OK_MESSAGE = 'Si un compte correspond à cette adresse, un e-mail de réinitialisation vient d’être envoyé.';

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuthMailer $authMailer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('/forgot-password', name: 'api_forgot_password', methods: ['POST'])]
    public function forgotPassword(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $email = isset($payload['email']) ? trim((string) $payload['email']) : '';
        $violations = $this->validator->validate($email, [
            new Assert\NotBlank(message: 'E-mail requis'),
            new Assert\Email(message: 'E-mail invalide'),
        ]);
        if (\count($violations) > 0) {
            return new JsonResponse(['error' => 'E-mail invalide'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user !== null) {
            $plainToken = bin2hex(random_bytes(32));
            $user->setPasswordResetToken($plainToken);
            $user->setPasswordResetExpiresAt(new \DateTimeImmutable('+1 hour'));
            $this->entityManager->flush();

            try {
                $this->authMailer->sendPasswordReset($user, $plainToken);
            } catch (\Throwable) {
                // ne pas révéler l’échec : même message neutre
            }
        }

        return new JsonResponse(['message' => self::OK_MESSAGE], Response::HTTP_OK);
    }

    #[Route('/reset-password', name: 'api_reset_password', methods: ['POST'])]
    public function resetPassword(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $token = isset($payload['token']) ? trim((string) $payload['token']) : '';
        $password = isset($payload['password']) ? (string) $payload['password'] : '';

        if ($token === '') {
            return new JsonResponse(['error' => 'Jeton manquant'], Response::HTTP_BAD_REQUEST);
        }

        $violations = $this->validator->validate($password, [
            new Assert\NotBlank(message: 'Mot de passe requis'),
            new Assert\Length(min: 8, minMessage: 'Le mot de passe doit contenir au moins 8 caractères'),
        ]);
        if (\count($violations) > 0) {
            $fields = [];
            foreach ($violations as $v) {
                $fields[$v->getPropertyPath() ?: 'password'] = $v->getMessage();
            }

            return new JsonResponse(['error' => 'Validation', 'fields' => $fields], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = $this->userRepository->findOneByPasswordResetToken($token);
        if ($user === null) {
            return new JsonResponse(['error' => 'Lien invalide ou expiré. Demandez un nouvel e-mail.'], Response::HTTP_BAD_REQUEST);
        }

        $expires = $user->getPasswordResetExpiresAt();
        if ($expires === null || $expires < new \DateTimeImmutable()) {
            return new JsonResponse(['error' => 'Lien expiré. Demandez un nouvel e-mail.'], Response::HTTP_BAD_REQUEST);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setPasswordResetToken(null);
        $user->setPasswordResetExpiresAt(null);
        $this->entityManager->flush();

        return new JsonResponse(['message' => 'Mot de passe mis à jour. Vous pouvez vous connecter.'], Response::HTTP_OK);
    }
}
