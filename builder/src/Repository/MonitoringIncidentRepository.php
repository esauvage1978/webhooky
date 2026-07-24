<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringIncident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringIncident>
 */
class MonitoringIncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringIncident::class);
    }

    public function countOpen(?int $organizationId = null): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->andWhere('i.status = :st')
            ->setParameter('st', MonitoringIncident::STATUS_OPEN);
        if ($organizationId !== null) {
            $qb->andWhere('i.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array{items: list<MonitoringIncident>, total: int}
     */
    public function findPaginated(int $offset, int $limit, ?string $status = null, ?int $organizationId = null): array
    {
        $qb = $this->createQueryBuilder('i')->orderBy('i.openedAt', 'DESC');
        if ($status !== null && $status !== '') {
            $qb->andWhere('i.status = :st')->setParameter('st', $status);
        }
        if ($organizationId !== null) {
            $qb->andWhere('i.organizationId = :oid')->setParameter('oid', $organizationId);
        }
        $total = (int) (clone $qb)->select('COUNT(i.id)')->getQuery()->getSingleScalarResult();
        $items = $qb->setFirstResult($offset)->setMaxResults($limit)->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @return list<MonitoringIncident>
     */
    public function findRecent(int $limit = 10, ?int $organizationId = null): array
    {
        $qb = $this->createQueryBuilder('i')
            ->orderBy('i.openedAt', 'DESC')
            ->setMaxResults($limit);
        if ($organizationId !== null) {
            $qb->andWhere('i.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return $qb->getQuery()->getResult();
    }
}
