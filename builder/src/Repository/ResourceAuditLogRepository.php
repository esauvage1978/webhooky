<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ResourceAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ResourceAuditLog>
 */
class ResourceAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ResourceAuditLog::class);
    }

    /**
     * @return list<ResourceAuditLog>
     */
    public function findForResource(string $resourceType, int $resourceId, int $limit = 150): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.resourceType = :t')->setParameter('t', $resourceType)
            ->andWhere('r.resourceId = :id')->setParameter('id', $resourceId)
            ->orderBy('r.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array{items: list<ResourceAuditLog>, total: int}
     */
    public function findAllPaginatedForAdmin(int $offset, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $total = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $items = $this->createQueryBuilder('r')
            ->orderBy('r.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<ResourceAuditLog> $items */
        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array{
     *   resourceType?: string|null,
     *   action?: string|null,
     *   organizationId?: int|null,
     *   actorUserId?: int|null,
     *   actorEmailContains?: string|null,
     *   resourceId?: int|null,
     *   dateFrom?: \DateTimeImmutable|null,
     *   dateTo?: \DateTimeImmutable|null,
     * } $filters
     *
     * @return array{items: list<ResourceAuditLog>, total: int}
     */
    public function findFilteredPaginatedForAdmin(array $filters, int $offset, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $qb = $this->createQueryBuilder('r');
        $qb->leftJoin('r.actorUser', 'auditActor')->addSelect('auditActor');
        $qb->leftJoin('r.organization', 'auditOrg')->addSelect('auditOrg');

        if (!empty($filters['resourceType'])) {
            $qb->andWhere('r.resourceType = :rt')->setParameter('rt', (string) $filters['resourceType']);
        }
        if (!empty($filters['action'])) {
            $qb->andWhere('r.action = :act')->setParameter('act', (string) $filters['action']);
        }
        if (!empty($filters['organizationId'])) {
            $qb->andWhere('IDENTITY(r.organization) = :oid')->setParameter('oid', (int) $filters['organizationId']);
        }
        if (!empty($filters['actorUserId'])) {
            $qb->andWhere('IDENTITY(r.actorUser) = :aid')->setParameter('aid', (int) $filters['actorUserId']);
        }
        if (!empty($filters['actorEmailContains'])) {
            $em = mb_strtolower(trim((string) $filters['actorEmailContains']));
            if ($em !== '') {
                $qb->andWhere('LOWER(auditActor.email) LIKE :aem')->setParameter('aem', '%'.$em.'%');
            }
        }
        if (!empty($filters['resourceId'])) {
            $qb->andWhere('r.resourceId = :rid')->setParameter('rid', (int) $filters['resourceId']);
        }
        if (($filters['dateFrom'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('r.occurredAt >= :df')->setParameter('df', $filters['dateFrom']);
        }
        if (($filters['dateTo'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('r.occurredAt <= :dt')->setParameter('dt', $filters['dateTo']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT r.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->select('r', 'auditActor', 'auditOrg')
            ->orderBy('r.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<ResourceAuditLog> $items */
        return ['items' => $items, 'total' => $total];
    }
}
