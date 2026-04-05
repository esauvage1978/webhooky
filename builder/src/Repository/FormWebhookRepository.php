<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhook;
use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormWebhook>
 */
class FormWebhookRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FormWebhook::class);
    }

    public function findOneWithActionsById(int $id): ?FormWebhook
    {
        return $this->createQueryBuilder('w')
            ->leftJoin('w.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('w.actions', 'a')->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->andWhere('w.id = :id')
            ->setParameter('id', $id)
            ->orderBy('a.sortOrder', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActiveByPublicToken(string $token): ?FormWebhook
    {
        return $this->createQueryBuilder('w')
            ->distinct()
            ->leftJoin('w.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('w.actions', 'a', 'WITH', 'a.active = true')
            ->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->andWhere('w.publicToken = :t')
            ->andWhere('w.active = :a')
            ->orderBy('a.sortOrder', 'ASC')
            ->setParameter('t', $token)
            ->setParameter('a', true)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<FormWebhook>
     */
    public function findByOrganizationOrdered(Organization $organization): array
    {
        return $this->findByOrganizationOrderedWithActions($organization);
    }

    /**
     * @return list<FormWebhook>
     */
    public function findByOrganizationOrderedWithActions(Organization $organization): array
    {
        return $this->createQueryBuilder('w')
            ->distinct()
            ->leftJoin('w.actions', 'a')->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->andWhere('w.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('w.name', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->addOrderBy('a.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<FormWebhook>
     */
    public function findAllOrderedWithActions(): array
    {
        return $this->createQueryBuilder('w')
            ->distinct()
            ->leftJoin('w.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('w.actions', 'a')->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->orderBy('w.name', 'ASC')
            ->addOrderBy('w.id', 'ASC')
            ->addOrderBy('a.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countByOrganization(Organization $organization): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.organization = :o')
            ->setParameter('o', $organization)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByOrganizationExcludingWebhook(Organization $organization, ?FormWebhook $exclude): int
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->andWhere('w.organization = :o')
            ->setParameter('o', $organization);
        if ($exclude !== null && $exclude->getId() !== null) {
            $qb->andWhere('w != :ex')->setParameter('ex', $exclude);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
