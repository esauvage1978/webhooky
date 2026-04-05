<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormWebhookLog>
 */
class FormWebhookLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormWebhookLog::class);
    }

    /**
     * @return list<FormWebhookLog>
     */
    public function findByWebhookPaginated(FormWebhook $webhook, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $ids = $this->createQueryBuilder('l')
            ->select('l.id')
            ->andWhere('l.formWebhook = :w')
            ->setParameter('w', $webhook)
            ->orderBy('l.receivedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
        if ($ids === []) {
            return [];
        }

        /** @var list<FormWebhookLog> $logs */
        $logs = $this->createQueryBuilder('l2')
            ->leftJoin('l2.actionLogs', 'al')->addSelect('al')
            ->where('l2.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->orderBy('l2.receivedAt', 'DESC')
            ->addOrderBy('al.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();

        return $logs;
    }

    public function findOneWithActionLogs(int $id): ?FormWebhookLog
    {
        return $this->createQueryBuilder('l')
            ->leftJoin('l.actionLogs', 'al')->addSelect('al')
            ->leftJoin('al.formWebhookAction', 'fa')->addSelect('fa')
            ->andWhere('l.id = :id')
            ->setParameter('id', $id)
            ->orderBy('al.sortOrder', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function countByWebhook(FormWebhook $webhook): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->andWhere('l.formWebhook = :w')
            ->setParameter('w', $webhook)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @param list<int> $webhookDatabaseIds
     *
     * @return array<int, int> id webhook => nombre de journaux
     */
    public function countGroupedByWebhookIds(array $webhookDatabaseIds): array
    {
        $webhookDatabaseIds = array_values(array_unique(array_filter($webhookDatabaseIds, static fn ($id) => $id !== null && $id !== 0)));
        if ($webhookDatabaseIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('IDENTITY(l.formWebhook) AS webhookId', 'COUNT(l.id) AS c')
            ->where('l.formWebhook IN (:ids)')
            ->setParameter('ids', $webhookDatabaseIds)
            ->groupBy('l.formWebhook')
            ->getQuery()
            ->getArrayResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['webhookId']] = (int) $row['c'];
        }

        return $map;
    }
}
