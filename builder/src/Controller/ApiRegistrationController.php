<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\AuthMailer;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException as DbalUniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api/register')]
final class ApiRegistrationController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly AuthMailer $authMailer,
        private readonly ValidatorInterface $validator,
    ) {
    }

    #[Route('', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['error' => 'JSON invalide'], Response::HTTP_BAD_REQUEST);
        }

        $email = isset($payload['email']) ? trim((string) $payload['email']) : '';
        $password = isset($payload['password']) ? (string) $payload['password'] : '';

        $fields = [];
        foreach ($this->validator->validate($email, [
            new Assert\NotBlank(message: 'E-mail requis'),
            new Assert\Email(message: 'E-mail invalide'),
        ]) as $v) {
            $fields['email'] = $v->getMessage();
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

        if ($this->userRepository->findOneBy(['email' => $email]) !== null) {
            return new JsonResponse(['error' => 'Un compte existe déjà avec cette adresse e-mail.'], Response::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setEmail($email)
            ->setRoles(['ROLE_MANAGER'])
            ->setEmailVerified(false);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        $plainToken = bin2hex(random_bytes(32));
        $user->setEmailVerificationToken($plainToken);
        $user->setEmailVerificationExpiresAt(new \DateTimeImmutable('+48 hours'));

        try {
            $this->entityManager->persist($user);
            $this->entityManager->flush();
        } catch (DbalUniqueConstraintViolationException) {
            return new JsonResponse(['error' => 'Un compte existe déjà avec cette adresse e-mail.'], Response::HTTP_CONFLICT);
        }

        try {
            $this->authMailer->sendEmailVerification($user, $plainToken);
        } catch (\Throwable) {
            return new JsonResponse(
                [
                    'error' => 'Compte créé mais l’e-mail de confirmation n’a pas pu être envoyé. Contactez l’administrateur ou réessayez plus tard.',
                ],
                Response::HTTP_BAD_GATEWAY,
            );
        }

        return new JsonResponse([
            'message' => 'Inscription enregistrée. Consultez votre boîte e-mail pour confirmer votre adresse avant de vous connecter.',
        ], Response::HTTP_CREATED);
    }
}
