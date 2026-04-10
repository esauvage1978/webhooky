<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Mailjet;
use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Mailjet>
 */
class MailjetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailjet::class);
    }

    /**
     * @return list<Mailjet>
     */
    public function findByOrganizationOrdered(Organization $organization): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.organization = :org')
            ->setParameter('org', $organization)
            ->orderBy('m.name', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Mailjet>
     */
    public function findAllOrderedForAdmin(): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.organization', 'o')
            ->orderBy('o.name', 'ASC')
            ->addOrderBy('m.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countCreatedBy(User $user): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.createdBy = :u')
            ->setParameter('u', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
