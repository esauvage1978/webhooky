<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\MonitoringAggregator;
use App\Monitoring\MonitoringMetricBuffer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:monitoring:aggregate', description: 'Agrège les métriques monitoring depuis les journaux webhook')]
final class MonitoringAggregateCommand extends Command
{
    public function __construct(
        private readonly MonitoringAggregator $aggregator,
        private readonly MonitoringMetricBuffer $metricBuffer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('hours', null, InputOption::VALUE_REQUIRED, 'Fenêtre en heures', '24');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $hours = max(1, (int) $input->getOption('hours'));
        $to = new \DateTimeImmutable('now');
        $from = $to->modify(sprintf('-%d hours', $hours));
        $this->metricBuffer->flushToDatabase();
        $n = $this->aggregator->aggregateFromLogs($from, $to);
        $io->success(sprintf('Agrégation OK — %d lignes touchées (%s → %s).', $n, $from->format('c'), $to->format('c')));

        return Command::SUCCESS;
    }
}
