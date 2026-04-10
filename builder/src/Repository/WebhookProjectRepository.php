<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\WebhookProject;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WebhookProject>
 */
class WebhookProjectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WebhookProject::class);
    }

    /**
     * @return list<WebhookProject>
     */
    public function findByOrganizationOrdered(Organization $organization): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<WebhookProject>
     */
    public function findAllOrderedForAdmin(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.organization', 'o')->addSelect('o')
            ->orderBy('o.name', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByOrganizationAndName(Organization $organization, string $name): ?WebhookProject
    {
        return $this->findOneBy(['organization' => $organization, 'name' => $name]);
    }

    public function findDefaultForOrganization(Organization $organization): ?WebhookProject
    {
        $byFlag = $this->findOneBy(['organization' => $organization, 'isDefault' => true]);

        return $byFlag ?? $this->findOneBy(['organization' => $organization, 'name' => WebhookProject::DEFAULT_NAME]);
    }

    public function countWebhooks(WebhookProject $project): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(w.id)')
            ->from(\App\Entity\FormWebhook::class, 'w')
            ->andWhere('w.project = :p')
            ->setParameter('p', $project)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
