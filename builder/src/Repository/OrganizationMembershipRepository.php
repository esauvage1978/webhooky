<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationMembership>
 */
class OrganizationMembershipRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMembership::class);
    }

    public function deleteForOrganization(Organization $organization): void
    {
        $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.organization = :o')
            ->setParameter('o', $organization)
            ->getQuery()
            ->execute();
    }
}
