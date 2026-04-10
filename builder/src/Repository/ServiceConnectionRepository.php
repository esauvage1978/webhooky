<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\ServiceConnection;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ServiceConnection>
 */
class ServiceConnectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ServiceConnection::class);
    }

    /**
     * @return list<ServiceConnection>
     */
    public function findByOrganizationOrdered(Organization $organization): array
    {
        return $this->createQueryBuilder('s')
            ->andWhere('s.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('s.type', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ServiceConnection>
     */
    public function findAllOrderedForAdmin(): array
    {
        return $this->createQueryBuilder('s')
            ->leftJoin('s.organization', 'org')->addSelect('org')
            ->orderBy('org.name', 'ASC')
            ->addOrderBy('s.type', 'ASC')
            ->addOrderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countUsagesInWebhookActions(int $serviceConnectionId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(a.id)')
            ->from(\App\Entity\FormWebhookAction::class, 'a')
            ->andWhere('a.serviceConnection = :id')
            ->setParameter('id', $serviceConnectionId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countCreatedBy(User $user): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->andWhere('s.createdBy = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
