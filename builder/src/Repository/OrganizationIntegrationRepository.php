<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationIntegration;
use App\Integration\OrganizationIntegrationType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationIntegration>
 */
class OrganizationIntegrationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationIntegration::class);
    }

    /**
     * Intégration GSC pour une organisation (la plus récente si plusieurs).
     */
    public function findLatestGscForOrganization(Organization $organization): ?OrganizationIntegration
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.organization = :org')
            ->andWhere('i.type = :t')
            ->setParameter('org', $organization)
            ->setParameter('t', OrganizationIntegrationType::GSC)
            ->orderBy('i.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<OrganizationIntegration>
     */
    public function findGscIntegrationsForOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.organization = :org')
            ->andWhere('i.type = :t')
            ->setParameter('org', $organization)
            ->setParameter('t', OrganizationIntegrationType::GSC)
            ->orderBy('i.id', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
