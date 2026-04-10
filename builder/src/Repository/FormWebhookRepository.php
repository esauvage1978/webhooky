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

    /**
     * Détail workflow : charge le webhook puis les actions par lazy-load.
     * Ne pas joindre `w.actions` sur la même requête que l’entité racine : avec plusieurs actions,
     * certaines configs Doctrine/SQL produisent une collection incomplète ou des erreurs silencieuses.
     */
    public function findOneWithActionsById(int $id): ?FormWebhook
    {
        $w = $this->createQueryBuilder('w')
            ->leftJoin('w.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('w.project', 'pr')->addSelect('pr')
            ->andWhere('w.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$w instanceof FormWebhook) {
            return null;
        }

        foreach ($w->getActions()->toArray() as $action) {
            $action->getMailjet();
            $action->getServiceConnection();
        }

        return $w;
    }

    public function findActiveByPublicToken(string $token): ?FormWebhook
    {
        $w = $this->findOneByPublicTokenForIngress($token);

        return ($w !== null && $w->isActive()) ? $w : null;
    }

    /**
     * Charge le workflow par jeton public (actif ou non), avec actions Mailjet actives — pour l’ingress.
     */
    public function findOneByPublicTokenForIngress(string $token): ?FormWebhook
    {
        return $this->createQueryBuilder('w')
            ->distinct()
            ->leftJoin('w.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('w.project', 'pr')->addSelect('pr')
            ->leftJoin('w.actions', 'a', 'WITH', 'a.active = true')
            ->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->leftJoin('a.serviceConnection', 'sc')->addSelect('sc')
            ->andWhere('w.publicToken = :t')
            ->orderBy('a.sortOrder', 'ASC')
            ->setParameter('t', $token)
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
            ->leftJoin('w.project', 'pr')->addSelect('pr')
            ->leftJoin('w.actions', 'a')->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->leftJoin('a.serviceConnection', 'sc')->addSelect('sc')
            ->andWhere('w.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('pr.name', 'ASC')
            ->addOrderBy('w.name', 'ASC')
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
            ->leftJoin('w.organization', 'org')->addSelect('org')
            ->leftJoin('w.project', 'pr')->addSelect('pr')
            ->leftJoin('w.actions', 'a')->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->leftJoin('a.serviceConnection', 'sc')->addSelect('sc')
            ->orderBy('org.name', 'ASC')
            ->addOrderBy('pr.name', 'ASC')
            ->addOrderBy('w.name', 'ASC')
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
