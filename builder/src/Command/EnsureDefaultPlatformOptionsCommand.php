<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Option;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:ensure-default-platform-options',
    description: 'Ajoute ou met à jour les options plateforme par défaut (catégorie « Options », option_category / option_domaine).',
)]
final class EnsureDefaultPlatformOptionsCommand extends Command
{
    private const CATEGORY = 'Options';

    /**
     * @var list<array{optionName: string, optionValue: string}>
     */
    private const DEFAULT_ROWS = [
        ['optionName' => 'option_category', 'optionValue' => 'Options;Hook'],
        ['optionName' => 'option_domaine', 'optionValue' => 'WebHooky'],
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $repo = $this->entityManager->getRepository(Option::class);

        $created = 0;
        $updated = 0;

        foreach (self::DEFAULT_ROWS as $row) {
            $option = $repo->createQueryBuilder('o')
                ->where('o.category = :cat')
                ->andWhere('o.optionName = :name')
                ->setParameter('cat', self::CATEGORY)
                ->setParameter('name', $row['optionName'])
                ->orderBy('o.id', 'ASC')
                ->setMaxResults(1)
                ->getQuery()
                ->getOneOrNullResult();

            if ($option instanceof Option) {
                if ($option->getOptionValue() !== $row['optionValue']) {
                    $option->setOptionValue($row['optionValue']);
                    ++$updated;
                }
                continue;
            }

            $option = (new Option())
                ->setCategory(self::CATEGORY)
                ->setOptionName($row['optionName'])
                ->setOptionValue($row['optionValue'])
                ->setDomain(null);
            $this->entityManager->persist($option);
            ++$created;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            'Options « %s » : %d créée(s), %d valeur(s) mise(s) à jour.',
            self::CATEGORY,
            $created,
            $updated,
        ));

        return Command::SUCCESS;
    }
}
