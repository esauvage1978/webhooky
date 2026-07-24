<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringMetricAgg;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringMetricAgg>
 */
class MonitoringMetricAggRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringMetricAgg::class);
    }

    /**
     * @param array<string, scalar> $dimensions
     */
    public function upsertAdd(
        string $periodType,
        \DateTimeImmutable $periodStart,
        string $metricKey,
        ?int $organizationId,
        array $dimensions,
        float $addSum,
        int $addCount,
        ?float $valueMax,
    ): MonitoringMetricAgg {
        $hash = self::hashDimensions($dimensions);
        $existing = $this->findOneBy([
            'periodType' => $periodType,
            'periodStart' => $periodStart,
            'metricKey' => $metricKey,
            'organizationId' => $organizationId,
            'dimensionHash' => $hash,
        ]);
        if ($existing === null) {
            $existing = new MonitoringMetricAgg();
            $existing->setPeriodType($periodType);
            $existing->setPeriodStart($periodStart);
            $existing->setMetricKey($metricKey);
            $existing->setOrganizationId($organizationId);
            $existing->setDimensionHash($hash);
            $existing->setDimensions($dimensions === [] ? null : $dimensions);
            $existing->setValueSum(0);
            $existing->setValueCount(0);
            $this->getEntityManager()->persist($existing);
        }
        $existing->setValueSum($existing->getValueSum() + $addSum);
        $existing->setValueCount($existing->getValueCount() + $addCount);
        if ($valueMax !== null) {
            $cur = $existing->getValueMax();
            $existing->setValueMax($cur === null ? $valueMax : max($cur, $valueMax));
        }

        return $existing;
    }

    /**
     * @return list<MonitoringMetricAgg>
     */
    public function findSeries(
        string $periodType,
        string $metricKey,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
        ?int $organizationId = null,
    ): array {
        $qb = $this->createQueryBuilder('m')
            ->andWhere('m.periodType = :pt')
            ->andWhere('m.metricKey = :mk')
            ->andWhere('m.periodStart >= :from')
            ->andWhere('m.periodStart < :to')
            ->setParameter('pt', $periodType)
            ->setParameter('mk', $metricKey)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('m.periodStart', 'ASC');
        if ($organizationId !== null) {
            $qb->andWhere('m.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return $qb->getQuery()->getResult();
    }

    public function purgeOlderThan(string $periodType, \DateTimeImmutable $before): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->andWhere('m.periodType = :pt')
            ->andWhere('m.periodStart < :before')
            ->setParameter('pt', $periodType)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * @param array<string, scalar> $dimensions
     */
    public static function hashDimensions(array $dimensions): string
    {
        if ($dimensions === []) {
            return sha1('{}');
        }
        ksort($dimensions);

        return sha1((string) json_encode($dimensions, \JSON_THROW_ON_ERROR));
    }
}
