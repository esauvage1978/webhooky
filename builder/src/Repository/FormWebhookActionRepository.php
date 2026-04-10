<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhookAction;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormWebhookAction>
 */
class FormWebhookActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormWebhookAction::class);
    }

    /**
     * Compte des workflows (déclencheurs) distincts et des actions liées à chaque connecteur.
     *
     * @param list<int> $serviceConnectionIds
     *
     * @return array<int, array{workflowCount: int, actionCount: int}>
     */
    public function aggregateUsageByServiceConnectionIds(array $serviceConnectionIds): array
    {
        $serviceConnectionIds = array_values(array_unique(array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $serviceConnectionIds,
        ), static fn (int $id): bool => $id > 0))));
        $out = [];
        foreach ($serviceConnectionIds as $id) {
            $out[$id] = ['workflowCount' => 0, 'actionCount' => 0];
        }
        if ($serviceConnectionIds === []) {
            return [];
        }

        $qb = $this->createQueryBuilder('a');
        $qb->select('IDENTITY(a.serviceConnection) AS sid')
            ->addSelect('COUNT(a.id) AS actionCount')
            ->addSelect('COUNT(DISTINCT w.id) AS workflowCount')
            ->join('a.formWebhook', 'w')
            ->andWhere($qb->expr()->in('IDENTITY(a.serviceConnection)', ':ids'))
            ->groupBy('a.serviceConnection')
            ->setParameter('ids', $serviceConnectionIds);

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $sid = (int) $row['sid'];
            if (!isset($out[$sid])) {
                continue;
            }
            $out[$sid] = [
                'workflowCount' => (int) $row['workflowCount'],
                'actionCount' => (int) $row['actionCount'],
            ];
        }

        return $out;
    }
}
