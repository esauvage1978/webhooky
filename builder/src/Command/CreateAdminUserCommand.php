<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-admin',
    description: 'Crée ou met à jour un administrateur, compte validé et prêt à la connexion (sans e-mail de confirmation)',
)]
final class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe (min. 12 caractères)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getOption('email')));
        $plainPassword = (string) $input->getOption('password');

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $email = (string) $io->ask('E-mail administrateur');
            $email = mb_strtolower(trim($email));
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $io->error('Une adresse e-mail valide est obligatoire (--email).');

            return Command::FAILURE;
        }

        if ($plainPassword === '') {
            $plainPassword = (string) $io->askHidden('Mot de passe (min. 12 caractères)');
        }

        if (!$this->isPasswordStrongEnough($plainPassword)) {
            $io->error('Mot de passe trop faible : 12 caractères minimum, avec lettres et chiffres.');

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $user = (new User())->setEmail($email);
            $this->entityManager->persist($user);
        }

        $user->setRoles(['ROLE_ADMIN']);
        $user->setSubscriptionExempt(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->markAccountAsValidatedAndReady($user);

        $this->entityManager->flush();

        $io->success(sprintf(
            'Administrateur enregistré : %s — compte actif, e-mail considéré comme vérifié (connexion immédiate possible).',
            $email,
        ));

        return Command::SUCCESS;
    }

    private function isPasswordStrongEnough(string $password): bool
    {
        if (strlen($password) < 12) {
            return false;
        }

        return (bool) preg_match('/[A-Za-z]/', $password) && (bool) preg_match('/\d/', $password);
    }

    /**
     * Aligné sur VerifiedUserChecker : accès autorisé sans étape e-mail / invitation.
     */
    private function markAccountAsValidatedAndReady(User $user): void
    {
        $user->setAccountEnabled(true);
        $user->setEmailVerified(true);

        $user->setEmailVerificationToken(null);
        $user->setEmailVerificationExpiresAt(null);

        $user->setPasswordResetToken(null);
        $user->setPasswordResetExpiresAt(null);

        $user->setInviteToken(null);
        $user->setInviteExpiresAt(null);
    }
}
