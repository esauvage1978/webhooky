<?php

declare(strict_types=1);

namespace App\Command;

use App\Monitoring\MonitoringCostCalculator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:monitoring:calculate-costs', description: 'Calcule les coûts unitaires (tarifs manuels)')]
final class MonitoringCalculateCostsCommand extends Command
{
    public function __construct(private readonly MonitoringCostCalculator $calculator)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('day', null, InputOption::VALUE_REQUIRED, 'Jour Y-m-d (défaut: hier)', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dayOpt = $input->getOption('day');
        $day = \is_string($dayOpt) && $dayOpt !== ''
            ? new \DateTimeImmutable($dayOpt)
            : new \DateTimeImmutable('yesterday');
        $n = $this->calculator->calculateDay($day);
        $io->success(sprintf('Coûts calculés pour %s — %d entrée(s).', $day->format('Y-m-d'), $n));

        return Command::SUCCESS;
    }
}
