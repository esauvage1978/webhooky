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
    public function __construct(
        ManagerRegistry $registry,
        private readonly OrganizationMonthlyEventUsageRepository $organizationMonthlyEventUsageRepository,
    ) {
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
            $now = new \DateTimeImmutable('now');
            $this->organizationMonthlyEventUsageRepository->incrementForOrganization(
                $organization,
                (int) $now->format('Y'),
                (int) $now->format('n'),
                $delta,
            );

            return true;
        });
    }

    /**
     * Génère un préfixe hexadécimal unique pour {@see Organization::webhookPublicPrefix}.
     */
    public function allocateUniqueWebhookPublicPrefix(): string
    {
        for ($i = 0; $i < 64; ++$i) {
            $prefix = bin2hex(random_bytes(6));
            $existing = $this->findOneBy(['webhookPublicPrefix' => $prefix]);
            if ($existing === null) {
                return $prefix;
            }
        }

        throw new \RuntimeException('Impossible d’allouer un préfixe webhook unique pour l’organisation.');
    }

    public function findOneByWebhookPublicPrefix(string $prefix): ?Organization
    {
        return $this->findOneBy(['webhookPublicPrefix' => strtolower($prefix)]);
    }

    /**
     * Remplit les préfixes manquants ou invalides (ex. après doctrine:schema:update sans migration).
     *
     * @return int nombre d’organisations mises à jour
     */
    public function ensureMissingWebhookPublicPrefixes(): int
    {
        $conn = $this->getEntityManager()->getConnection();
        try {
            $ids = $conn->fetchFirstColumn(
                'SELECT id FROM organization WHERE webhook_public_prefix IS NULL OR webhook_public_prefix = \'\' OR CHAR_LENGTH(webhook_public_prefix) <> 12',
            );
        } catch (\Throwable) {
            return 0;
        }

        if ($ids === []) {
            return 0;
        }

        $n = 0;
        foreach ($ids as $id) {
            $prefix = $this->allocateUniqueWebhookPublicPrefix();
            $conn->executeStatement(
                'UPDATE organization SET webhook_public_prefix = ? WHERE id = ?',
                [$prefix, (int) $id],
            );
            ++$n;
        }

        return $n;
    }
}
