<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\MonitoringAlertEvaluator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:monitoring:evaluate-alerts', description: 'Évalue les règles d’alertes monitoring')]
final class MonitoringEvaluateAlertsCommand extends Command
{
    public function __construct(private readonly MonitoringAlertEvaluator $evaluator)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $n = $this->evaluator->evaluate();
        $io->success(sprintf('Alertes évaluées — %d upsert(s).', $n));

        return Command::SUCCESS;
    }
}
