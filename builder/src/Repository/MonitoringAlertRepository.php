<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringAlert;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringAlert>
 */
class MonitoringAlertRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringAlert::class);
    }

    public function findByFingerprint(string $fingerprint): ?MonitoringAlert
    {
        return $this->findOneBy(['fingerprint' => $fingerprint]);
    }

    public function countOpenCritical(?int $organizationId = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status IN (:st)')
            ->andWhere('a.severity = :sev')
            ->setParameter('st', [MonitoringAlert::STATUS_OPEN, MonitoringAlert::STATUS_ACKNOWLEDGED])
            ->setParameter('sev', MonitoringAlert::SEVERITY_CRITICAL);
        if ($organizationId !== null) {
            $qb->andWhere('a.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countOpen(?int $organizationId = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.status IN (:st)')
            ->setParameter('st', [MonitoringAlert::STATUS_OPEN, MonitoringAlert::STATUS_ACKNOWLEDGED]);
        if ($organizationId !== null) {
            $qb->andWhere('a.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{items: list<MonitoringAlert>, total: int}
     */
    public function findPaginated(int $offset, int $limit, ?string $status = null, ?int $organizationId = null): array
    {
        $qb = $this->createQueryBuilder('a')->orderBy('a.lastSeenAt', 'DESC');
        if ($status !== null && $status !== '') {
            $qb->andWhere('a.status = :st')->setParameter('st', $status);
        }
        if ($organizationId !== null) {
            $qb->andWhere('a.organizationId = :oid')->setParameter('oid', $organizationId);
        }
        $total = (int) (clone $qb)->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<MonitoringAlert>
     */
    public function findRecentOpen(int $limit = 10, ?int $organizationId = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('a.status IN (:st)')
            ->setParameter('st', [MonitoringAlert::STATUS_OPEN, MonitoringAlert::STATUS_ACKNOWLEDGED])
            ->orderBy('a.lastSeenAt', 'DESC')
            ->setMaxResults($limit);
        if ($organizationId !== null) {
            $qb->andWhere('a.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return $qb->getQuery()->getResult();
    }

    public function purgeResolvedBefore(\DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('a')
            ->delete()
            ->andWhere('a.status = :st')
            ->andWhere('a.resolvedAt < :before')
            ->setParameter('st', MonitoringAlert::STATUS_RESOLVED)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }
}
