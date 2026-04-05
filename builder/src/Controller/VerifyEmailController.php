<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class VerifyEmailController extends AbstractController
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/verify-email', name: 'verify_email', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $token = $request->query->getString('token');
        if ($token === '') {
            return $this->redirectToFront('verify_error=missing_token');
        }

        $user = $this->userRepository->findOneByEmailVerificationToken($token);
        if ($user === null) {
            return $this->redirectToFront('verify_error=invalid_token');
        }

        $expires = $user->getEmailVerificationExpiresAt();
        if ($expires !== null && $expires < new \DateTimeImmutable()) {
            return $this->redirectToFront('verify_error=expired');
        }

        $user->setEmailVerified(true);
        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationExpiresAt(null);
        $this->entityManager->flush();

        return $this->redirectToFront('verified=1');
    }

    private function redirectToFront(string $query): Response
    {
        return $this->redirect('/?'.$query);
    }
}
