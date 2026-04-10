<?php

declare(strict_types=1);

namespace App\Command;

use App\WebhookProject\DefaultWebhookProjectService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ensure-default-webhook-projects',
    description: 'Crée le projet « Général » par organisation, corrige les project_id invalides et rattache les workflows sans projet (à lancer avant schema:update si la FK project_id échoue).',
)]
final class EnsureDefaultWebhookProjectsCommand extends Command
{
    public function __construct(
        private readonly DefaultWebhookProjectService $defaultWebhookProjectService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->defaultWebhookProjectService->ensureAllOrganizationsHaveDefaultAndAttachWebhooks();
        $io->success(sprintf(
            'Organisations : %d | Nouveaux projets « Général » : %d | Workflows avec projet invalide corrigés : %d | Workflows sans projet rattachés : %d',
            $stats['organizations'],
            $stats['defaultsCreated'],
            $stats['danglingProjectIdsFixed'],
            $stats['webhooksAttached'],
        ));

        return Command::SUCCESS;
    }
}
