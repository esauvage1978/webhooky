<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\OrganizationMonthlyEventUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrganizationMonthlyEventUsage>
 */
class OrganizationMonthlyEventUsageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationMonthlyEventUsage::class);
    }

    /**
     * Incrémente le compteur du mois civil (upsert atomique, même transaction que le total organisation).
     */
    public function incrementForOrganization(Organization $organization, int $year, int $month, int $delta): void
    {
        if ($delta < 1) {
            return;
        }
        $oid = $organization->getId();
        if ($oid === null) {
            return;
        }

        $conn = $this->getEntityManager()->getConnection();
        $conn->executeStatement(
            'INSERT INTO organization_monthly_event_usage (organization_id, year, month, event_count) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE event_count = event_count + ?',
            [$oid, $year, $month, $delta, $delta],
        );
    }

    /**
     * @param list<int> $organizationIds
     *
     * @return array<int, int> id organisation → événements du mois
     */
    public function countsByOrganizationIdsForYearMonth(array $organizationIds, int $year, int $month): array
    {
        $organizationIds = array_values(array_unique(array_filter(
            $organizationIds,
            static fn ($id) => \is_int($id) && $id > 0,
        )));
        if ($organizationIds === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('u')
            ->select('o.id AS oid', 'u.eventCount AS c')
            ->join('u.organization', 'o')
            ->where('o.id IN (:ids)')
            ->andWhere('u.year = :y')
            ->andWhere('u.month = :m')
            ->setParameter('ids', $organizationIds, ArrayParameterType::INTEGER)
            ->setParameter('y', $year)
            ->setParameter('m', $month)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $oid = isset($row['oid']) ? (int) $row['oid'] : 0;
            if ($oid > 0) {
                $out[$oid] = (int) ($row['c'] ?? 0);
            }
        }

        return $out;
    }

    public function getCountForOrganizationYearMonth(Organization $organization, int $year, int $month): int
    {
        $row = $this->findOneBy([
            'organization' => $organization,
            'year' => $year,
            'month' => $month,
        ]);

        return $row instanceof OrganizationMonthlyEventUsage ? $row->getEventCount() : 0;
    }

    /**
     * @param list<array{year: int, month: int}> $yearMonthTuples
     *
     * @return array<string, int> clé « Y-m » → nombre d’événements quota
     */
    public function countsByYearMonthKeysForOrganization(Organization $organization, array $yearMonthTuples): array
    {
        $codes = [];
        foreach ($yearMonthTuples as $tuple) {
            $y = (int) ($tuple['year'] ?? 0);
            $m = (int) ($tuple['month'] ?? 0);
            if ($y >= 1 && $m >= 1 && $m <= 12) {
                $codes[] = $y * 100 + $m;
            }
        }
        $codes = array_values(array_unique($codes));
        if ($codes === []) {
            return [];
        }

        $rows = $this->createQueryBuilder('u')
            ->select('u.year', 'u.month', 'u.eventCount')
            ->where('u.organization = :o')
            ->andWhere('(u.year * 100 + u.month) IN (:codes)')
            ->setParameter('o', $organization)
            ->setParameter('codes', $codes, ArrayParameterType::INTEGER)
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $row) {
            $key = \sprintf('%04d-%02d', (int) ($row['year'] ?? 0), (int) ($row['month'] ?? 0));
            $out[$key] = (int) ($row['eventCount'] ?? 0);
        }

        return $out;
    }

    public function deleteAllForOrganization(Organization $organization): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(OrganizationMonthlyEventUsage::class, 'u')
            ->where('u.organization = :o')
            ->setParameter('o', $organization)
            ->getQuery()
            ->execute();
    }
}
