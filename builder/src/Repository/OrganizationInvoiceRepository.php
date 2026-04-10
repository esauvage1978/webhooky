<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationInvoice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationInvoice>
 */
final class OrganizationInvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationInvoice::class);
    }

    /**
     * @return list<OrganizationInvoice>
     */
    public function findByOrganizationOrdered(Organization $organization, int $limit = 200): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.organization = :org')
            ->setParameter('org', $organization)
            ->orderBy('i.issuedAt', 'DESC')
            ->addOrderBy('i.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
