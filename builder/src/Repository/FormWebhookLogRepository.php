<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhook;
use App\Entity\FormWebhookLog;
use App\Entity\Organization;
use App\FormWebhook\FormWebhookLogStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
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
    public function findByWebhookPaginated(
        FormWebhook $webhook,
        int $page,
        int $limit,
        ?string $status = null,
        ?string $search = null,
    ): array {
        $page = max(1, $page);
        $limit = min(100, max(1, $limit));

        $idsQb = $this->createQueryBuilder('l')
            ->select('l.id')
            ->orderBy('l.receivedAt', 'DESC')
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit);
        $this->applyWebhookListFilters($idsQb, $webhook, $status, $search);
        if ($this->searchNeedsActionJoin($search)) {
            $idsQb->distinct();
        }

        $ids = $idsQb->getQuery()->getSingleColumnResult();
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

    public function countByWebhook(FormWebhook $webhook, ?string $status = null, ?string $search = null): int
    {
        $qb = $this->createQueryBuilder('l');
        if ($this->searchNeedsActionJoin($search)) {
            $qb->select('COUNT(DISTINCT l.id)');
        } else {
            $qb->select('COUNT(l.id)');
        }
        $this->applyWebhookListFilters($qb, $webhook, $status, $search);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function searchNeedsActionJoin(?string $search): bool
    {
        return $search !== null && trim($search) !== '';
    }

    private function applyWebhookListFilters(
        QueryBuilder $qb,
        FormWebhook $webhook,
        ?string $status,
        ?string $search,
    ): void {
        $qb->andWhere('l.formWebhook = :w')->setParameter('w', $webhook);

        $status = $status !== null ? trim($status) : '';
        if ($status !== '' && \in_array($status, FormWebhookLogStatus::all(), true)) {
            $qb->andWhere('l.status = :status')->setParameter('status', $status);
        }

        $search = $search !== null ? trim($search) : '';
        if ($search === '') {
            return;
        }

        $like = '%'.mb_strtolower($search).'%';
        $qb->leftJoin('l.actionLogs', 'alSearch');
        $ors = [
            $qb->expr()->like('LOWER(COALESCE(l.errorDetail, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(l.clientIp, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(l.rawBody, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(l.userAgent, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(l.status)', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(alSearch.toEmail, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(alSearch.mailjetMessageId, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(alSearch.errorDetail, \'\'))', ':searchLike'),
            $qb->expr()->like('LOWER(COALESCE(alSearch.mailjetResponseBody, \'\'))', ':searchLike'),
        ];
        if (ctype_digit($search)) {
            $ors[] = $qb->expr()->eq('l.id', ':searchId');
            $qb->setParameter('searchId', (int) $search);
        }
        $qb->andWhere($qb->expr()->orX(...$ors))->setParameter('searchLike', $like);
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
     * Ingress par organisation sur [from, to) — une requête pour la liste admin.
     *
     * @param list<int> $organizationIds
     * @return array<int, int> organization id => nombre de lignes de journal
     */
    public function countIngressForOrganizationIdsBetween(
        array $organizationIds,
        \DateTimeImmutable $fromInclusive,
        \DateTimeImmutable $toExclusive,
    ): array {
        if ($organizationIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('l')
            ->select('o.id AS oid', 'COUNT(l.id) AS cnt')
            ->join('l.formWebhook', 'w')
            ->join('w.organization', 'o')
            ->andWhere('o.id IN (:ids)')
            ->andWhere('l.receivedAt >= :from')
            ->andWhere('l.receivedAt < :to')
            ->setParameter('ids', $organizationIds, ArrayParameterType::INTEGER)
            ->setParameter('from', $fromInclusive)
            ->setParameter('to', $toExclusive)
            ->groupBy('o.id')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['oid']] = (int) $r['cnt'];
        }

        return $out;
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
