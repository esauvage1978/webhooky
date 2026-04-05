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
}
