<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FormWebhook;
use App\Entity\Organization;
use App\FormWebhook\FormWebhookIngressTokenParser;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FormWebhook>
 */
class FormWebhookRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly OrganizationRepository $organizationRepository,
    ) {
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
        $parsed = FormWebhookIngressTokenParser::parseComposite(strtolower($token));
        if (\is_array($parsed)) {
            $org = $this->organizationRepository->findOneByWebhookPublicPrefix($parsed['prefix']);
            if ($org === null) {
                return null;
            }

            return $this->findOneByPublicTokenForIngressWithOrg($parsed['workflowPublicToken'], $org);
        }

        $legacyUuid = FormWebhookIngressTokenParser::parseLegacyUuidOnly($token);
        if ($legacyUuid !== null) {
            return $this->findOneByPublicTokenForIngressWithOrg($legacyUuid, null);
        }

        return null;
    }

    /**
     * @param non-empty-string $workflowPublicToken Jeton UUID stocké sur le workflow (sans préfixe org).
     */
    private function findOneByPublicTokenForIngressWithOrg(string $workflowPublicToken, ?Organization $expectedOrg): ?FormWebhook
    {
        $qb = $this->createQueryBuilder('w')
            ->distinct()
            ->leftJoin('w.createdBy', 'cb')->addSelect('cb')
            ->leftJoin('w.organization', 'org')->addSelect('org')
            ->leftJoin('w.project', 'pr')->addSelect('pr')
            ->leftJoin('w.actions', 'a', 'WITH', 'a.active = true')
            ->addSelect('a')
            ->leftJoin('a.mailjet', 'mj')->addSelect('mj')
            ->leftJoin('a.serviceConnection', 'sc')->addSelect('sc')
            ->andWhere('w.publicToken = :t')
            ->setParameter('t', $workflowPublicToken)
            ->orderBy('a.sortOrder', 'ASC');

        if ($expectedOrg !== null) {
            $qb->andWhere('w.organization = :org')->setParameter('org', $expectedOrg);
        }

        return $qb->getQuery()->getOneOrNullResult();
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
