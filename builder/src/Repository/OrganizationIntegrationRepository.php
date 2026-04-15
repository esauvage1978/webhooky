<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrganizationIntegration;
use App\Entity\WebhookProject;
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

    public function findGscForProject(WebhookProject $project): ?OrganizationIntegration
    {
        return $this->createQueryBuilder('i')
            ->andWhere('i.project = :p')
            ->andWhere('i.type = :t')
            ->setParameter('p', $project)
            ->setParameter('t', OrganizationIntegrationType::GSC)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function removeGscForProject(WebhookProject $project): void
    {
        $row = $this->findGscForProject($project);
        if (!$row instanceof OrganizationIntegration) {
            return;
        }
        $this->getEntityManager()->remove($row);
    }
}
