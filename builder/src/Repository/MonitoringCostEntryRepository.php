<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringCostEntry;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringCostEntry>
 */
class MonitoringCostEntryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringCostEntry::class);
    }

    /**
     * @return list<MonitoringCostEntry>
     */
    public function findForPeriod(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $organizationId = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->andWhere('c.periodDay >= :from')
            ->andWhere('c.periodDay <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('c.periodDay', 'ASC');
        if ($organizationId !== null) {
            $qb->andWhere('c.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return $qb->getQuery()->getResult();
    }

    public function upsertDay(
        \DateTimeImmutable $day,
        ?int $organizationId,
        string $channel,
        string $provider,
        float $units,
        int $costCents,
        string $currency,
        ?int $pricingRuleId,
    ): MonitoringCostEntry {
        $day = $day->setTime(0, 0);
        $existing = $this->findOneBy([
            'periodDay' => $day,
            'organizationId' => $organizationId,
            'channel' => $channel,
            'provider' => $provider,
        ]);
        if ($existing === null) {
            $existing = new MonitoringCostEntry();
            $existing->setPeriodDay($day);
            $existing->setOrganizationId($organizationId);
            $existing->setChannel($channel);
            $existing->setProvider($provider);
            $this->getEntityManager()->persist($existing);
        }
        $existing->setUnits($units);
        $existing->setCostCents($costCents);
        $existing->setCurrency($currency);
        $existing->setPricingRuleId($pricingRuleId);

        return $existing;
    }

    public function sumCostCents(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $organizationId = null): int
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COALESCE(SUM(c.costCents), 0)')
            ->andWhere('c.periodDay >= :from')
            ->andWhere('c.periodDay <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);
        if ($organizationId !== null) {
            $qb->andWhere('c.organizationId = :oid')->setParameter('oid', $organizationId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
