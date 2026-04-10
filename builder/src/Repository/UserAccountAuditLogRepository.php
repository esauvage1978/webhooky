<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\UserAccountAuditLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserAccountAuditLog>
 */
class UserAccountAuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAccountAuditLog::class);
    }

    /**
     * @return list<UserAccountAuditLog>
     */
    public function findRecentForOrganization(Organization $organization, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('a.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<UserAccountAuditLog>
     */
    public function findRecentAll(int $limit = 200): array
    {
        return $this->createQueryBuilder('a')
            ->orderBy('a.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{
     *   organizationId?: int|null,
     *   action?: string|null,
     *   actorEmailContains?: string|null,
     *   targetEmailContains?: string|null,
     *   dateFrom?: \DateTimeImmutable|null,
     *   dateTo?: \DateTimeImmutable|null,
     * } $filters
     *
     * @return array{items: list<UserAccountAuditLog>, total: int}
     */
    public function findFilteredPaginatedForAdmin(array $filters, int $offset, int $limit): array
    {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $qb = $this->createQueryBuilder('a');
        $qb->leftJoin('a.actorUser', 'actor')->addSelect('actor');
        $qb->leftJoin('a.organization', 'org')->addSelect('org');
        $qb->leftJoin('a.targetUser', 'target')->addSelect('target');

        if (!empty($filters['organizationId'])) {
            $qb->andWhere('IDENTITY(a.organization) = :oid')->setParameter('oid', (int) $filters['organizationId']);
        }
        if (!empty($filters['action'])) {
            $qb->andWhere('a.action = :act')->setParameter('act', (string) $filters['action']);
        }
        if (!empty($filters['actorEmailContains'])) {
            $em = mb_strtolower(trim((string) $filters['actorEmailContains']));
            if ($em !== '') {
                $qb->andWhere('LOWER(actor.email) LIKE :aem')->setParameter('aem', '%'.$em.'%');
            }
        }
        if (!empty($filters['targetEmailContains'])) {
            $tm = mb_strtolower(trim((string) $filters['targetEmailContains']));
            if ($tm !== '') {
                $qb->andWhere('LOWER(a.targetEmail) LIKE :tem')->setParameter('tem', '%'.$tm.'%');
            }
        }
        if (($filters['dateFrom'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('a.occurredAt >= :df')->setParameter('df', $filters['dateFrom']);
        }
        if (($filters['dateTo'] ?? null) instanceof \DateTimeImmutable) {
            $qb->andWhere('a.occurredAt <= :dt')->setParameter('dt', $filters['dateTo']);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(DISTINCT a.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->select('a', 'actor', 'org', 'target')
            ->orderBy('a.occurredAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<UserAccountAuditLog> $items */
        return ['items' => $items, 'total' => $total];
    }
}
