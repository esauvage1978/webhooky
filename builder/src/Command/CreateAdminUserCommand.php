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
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail', 'emmanuel.sauvage@live.fr')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe', 'Fckgwrhqq101');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getOption('email')));
        $plainPassword = (string) $input->getOption('password');

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $user = (new User())->setEmail($email);
            $this->entityManager->persist($user);
        }

        $user->setRoles(['ROLE_ADMIN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->markAccountAsValidatedAndReady($user);

        $this->entityManager->flush();

        $io->success(sprintf(
            'Administrateur enregistré : %s — compte actif, e-mail considéré comme vérifié (connexion immédiate possible).',
            $email,
        ));

        return Command::SUCCESS;
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
