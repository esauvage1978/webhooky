<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\MonitoringRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:monitoring:retention', description: 'Purge payloads/logs selon rétention par plan')]
final class MonitoringRetentionCommand extends Command
{
    public function __construct(private readonly MonitoringRetentionService $retentionService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $stats = $this->retentionService->purge();
        $io->success('Rétention OK : '.json_encode($stats, \JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
