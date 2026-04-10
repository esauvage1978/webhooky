<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Organization;
use App\Entity\User;
use App\Repository\UserRepository;
use App\WebhookProject\DefaultWebhookProjectService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:create-platform-manager',
    description: 'Crée le gestionnaire plateforme (contact@webhooky.fr), organisation interne hors forfait et compte prêt à l’emploi.',
)]
final class CreatePlatformManagerCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly DefaultWebhookProjectService $defaultWebhookProjectService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'Adresse e-mail du gestionnaire', 'contact@webhooky.builders')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'Mot de passe', 'Fckgwrhqq101')
            ->addOption('organization-name', null, InputOption::VALUE_REQUIRED, 'Nom de l’organisation interne', 'Webhooky (interne)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = mb_strtolower(trim((string) $input->getOption('email')));
        $plainPassword = trim((string) $input->getOption('password'));
        $orgName = trim((string) $input->getOption('organization-name'));

        if ($plainPassword === '') {
            $io->error('L’option --password est obligatoire.');

            return Command::FAILURE;
        }

        if ($orgName === '') {
            $io->error('Le nom d’organisation ne peut pas être vide.');

            return Command::FAILURE;
        }

        $organization = $this->entityManager->getRepository(Organization::class)->findOneBy(['name' => $orgName]);
        if ($organization === null) {
            $organization = (new Organization())->setName($orgName);
            $organization->applyFreePlan();
            $this->entityManager->persist($organization);
        }

        $organization->setSubscriptionExempt(true);

        $this->defaultWebhookProjectService->ensureDefaultForOrganization($organization);

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if ($user === null) {
            $user = (new User())->setEmail($email);
            $this->entityManager->persist($user);
        }

        $user->setRoles(['ROLE_MANAGER']);
        $user->setSubscriptionExempt(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->markAccountAsValidatedAndReady($user);

        $local = explode('@', $email)[0] ?? 'Contact';
        $user->setDisplayName($local !== '' ? $local : 'Contact');
        $user->setProfileCompletedAt(new \DateTimeImmutable());
        $user->setPlanOnboardingCompleted(true);

        $user->addOrganizationMembership($organization);
        $user->setOrganization($organization);

        $this->entityManager->flush();

        $io->success(sprintf(
            'Gestionnaire plateforme enregistré : %s — organisation « %s » (hors forfait), compte actif.',
            $email,
            $orgName,
        ));

        return Command::SUCCESS;
    }

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
