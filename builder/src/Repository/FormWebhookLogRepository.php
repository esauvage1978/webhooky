<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookLog;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * Dernier journal par workflow (ligne au plus grand id), pour affichage liste.
     *
     * @param list<int> $webhookIds
     *
     * @return array<int, array{status: string, receivedAt: string|null, errorDetail: string|null}>
     */
    public function lastLogSummaryByWebhookIds(array $webhookIds): array
    {
        $webhookIds = array_values(array_unique(array_filter(
            $webhookIds,
            static fn ($id) => \is_int($id) || (\is_string($id) && ctype_digit($id)),
        )));
        $webhookIds = array_map(static fn ($id) => (int) $id, $webhookIds);
        if ($webhookIds === []) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $sql = <<<'SQL'
            SELECT l.form_webhook_id AS webhookId, l.status, l.error_detail AS errorDetail, l.received_at AS receivedAt
            FROM form_webhook_log l
            INNER JOIN (
                SELECT form_webhook_id, MAX(id) AS max_id
                FROM form_webhook_log
                WHERE form_webhook_id IN (:ids)
                GROUP BY form_webhook_id
            ) t ON l.id = t.max_id
            SQL;

        $result = $conn->executeQuery(
            $sql,
            ['ids' => $webhookIds],
            ['ids' => ArrayParameterType::INTEGER],
        );

        $out = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $wid = (int) $row['webhookId'];
            $receivedRaw = $row['receivedAt'] ?? null;
            $receivedAt = null;
            if ($receivedRaw instanceof \DateTimeInterface) {
                $receivedAt = $receivedRaw->format(\DateTimeInterface::ATOM);
            } elseif (\is_string($receivedRaw) && $receivedRaw !== '') {
                try {
                    $receivedAt = (new \DateTimeImmutable($receivedRaw))->format(\DateTimeInterface::ATOM);
                } catch (\Exception) {
                    $receivedAt = null;
                }
            }
            $err = $row['errorDetail'] ?? null;
            $out[$wid] = [
                'status' => (string) ($row['status'] ?? ''),
                'receivedAt' => $receivedAt,
                'errorDetail' => $err !== null && $err !== '' ? (string) $err : null,
            ];
        }

        return $out;
    }

    /**
     * Nombre d’ingress (lignes de journal) pour une organisation sur une période semi-ouverte [from, to).
     */
    public function countIngressForOrganizationBetween(Organization $organization, \DateTimeImmutable $fromInclusive, \DateTimeImmutable $toExclusive): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->join('l.formWebhook', 'w')
            ->andWhere('w.organization = :org')
            ->andWhere('l.receivedAt >= :from')
            ->andWhere('l.receivedAt < :to')
            ->setParameter('org', $organization)
            ->setParameter('from', $fromInclusive)
            ->setParameter('to', $toExclusive)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Réceptions agrégées par projet (lignes de journal d’ingress) sur [from, to).
     *
     * @return list<array{projectId: int, projectName: string, ingressCount: int}>
     */
    public function aggregateIngressByProjectForOrganizationBetween(
        Organization $organization,
        \DateTimeImmutable $fromInclusive,
        \DateTimeImmutable $toExclusive,
    ): array {
        $rows = $this->createQueryBuilder('l')
            ->select('p.id AS projectId', 'p.name AS projectName', 'COUNT(l.id) AS cnt')
            ->join('l.formWebhook', 'w')
            ->join('w.project', 'p')
            ->andWhere('w.organization = :org')
            ->andWhere('l.receivedAt >= :from')
            ->andWhere('l.receivedAt < :to')
            ->setParameter('org', $organization)
            ->setParameter('from', $fromInclusive)
            ->setParameter('to', $toExclusive)
            ->groupBy('p.id')
            ->addGroupBy('p.name')
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => [
            'projectId' => (int) $r['projectId'],
            'projectName' => (string) $r['projectName'],
            'ingressCount' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Réceptions agrégées par workflow sur [from, to).
     *
     * @return list<array{webhookId: int, webhookName: string, projectId: int, projectName: string, ingressCount: int}>
     */
    public function aggregateIngressByWebhookForOrganizationBetween(
        Organization $organization,
        \DateTimeImmutable $fromInclusive,
        \DateTimeImmutable $toExclusive,
    ): array {
        $rows = $this->createQueryBuilder('l')
            ->select(
                'w.id AS webhookId',
                'w.name AS webhookName',
                'p.id AS projectId',
                'p.name AS projectName',
                'COUNT(l.id) AS cnt',
            )
            ->join('l.formWebhook', 'w')
            ->join('w.project', 'p')
            ->andWhere('w.organization = :org')
            ->andWhere('l.receivedAt >= :from')
            ->andWhere('l.receivedAt < :to')
            ->setParameter('org', $organization)
            ->setParameter('from', $fromInclusive)
            ->setParameter('to', $toExclusive)
            ->groupBy('w.id')
            ->addGroupBy('w.name')
            ->addGroupBy('p.id')
            ->addGroupBy('p.name')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('w.name', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r) => [
            'webhookId' => (int) $r['webhookId'],
            'webhookName' => (string) $r['webhookName'],
            'projectId' => (int) $r['projectId'],
            'projectName' => (string) $r['projectName'],
            'ingressCount' => (int) $r['cnt'],
        ], $rows);
    }
}
