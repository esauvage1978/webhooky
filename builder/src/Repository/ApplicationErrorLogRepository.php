<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApplicationErrorLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApplicationErrorLog>
 */
class ApplicationErrorLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApplicationErrorLog::class);
    }

    /**
     * @return array{items: list<ApplicationErrorLog>, total: int}
     */
    public function findPaginatedForAdmin(
        int $offset,
        int $limit,
        ?\DateTimeImmutable $dateFrom = null,
        ?\DateTimeImmutable $dateTo = null,
    ): array {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $qb = $this->createQueryBuilder('e');
        if ($dateFrom instanceof \DateTimeImmutable) {
            $qb->andWhere('e.createdAt >= :df')->setParameter('df', $dateFrom);
        }
        if ($dateTo instanceof \DateTimeImmutable) {
            $qb->andWhere('e.createdAt <= :dt')->setParameter('dt', $dateTo);
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(e.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->orderBy('e.createdAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<ApplicationErrorLog> $items */
        return ['items' => $items, 'total' => $total];
    }
}
