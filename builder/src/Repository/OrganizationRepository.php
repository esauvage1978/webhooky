<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Organization>
 */
class OrganizationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Organization::class);
    }

    /**
     * @return list<Organization>
     */
    public function findByNameAsc(): array
    {
        return $this->createQueryBuilder('o')
            ->orderBy('o.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Incrémente le compteur d’événements de `$delta` si le plafond n’est pas dépassé.
     */
    public function incrementEventsConsumedIfBelowCap(Organization $organization, int $cap, int $delta = 1): bool
    {
        if ($delta < 1) {
            return true;
        }

        $em = $this->getEntityManager();

        return (bool) $em->wrapInTransaction(function () use ($em, $organization, $cap, $delta): bool {
            $em->lock($organization, LockMode::PESSIMISTIC_WRITE);
            $em->refresh($organization);
            if ($organization->getEventsConsumed() + $delta > $cap) {
                return false;
            }
            $organization->setEventsConsumed($organization->getEventsConsumed() + $delta);

            return true;
        });
    }
}
