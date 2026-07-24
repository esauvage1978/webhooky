<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\MonitoringMetricAgg;
use App\Entity\Organization;
use App\Repository\MonitoringAlertRepository;
use App\Repository\MonitoringMetricAggRepository;
use App\Subscription\SubscriptionPlan;
use Doctrine\ORM\EntityManagerInterface;

final class MonitoringRetentionService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MonitoringMetricAggRepository $metricAggRepository,
        private readonly MonitoringAlertRepository $alertRepository,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function purge(): array
    {
        $stats = [
            'payloadsCleared' => 0,
            'logsDeleted' => 0,
            'alertsPurged' => 0,
            'metricsPurged' => 0,
        ];

        /** @var list<Organization> $orgs */
        $orgs = $this->entityManager->createQuery('SELECT o FROM App\Entity\Organization o')->getResult();
        $conn = $this->entityManager->getConnection();

        foreach ($orgs as $org) {
            $plan = $org->getSubscriptionPlan();
            [$payloadDays, $logDays] = $this->retentionDays($plan);
            $payloadBefore = (new \DateTimeImmutable())->modify(sprintf('-%d days', $payloadDays));
            $logBefore = (new \DateTimeImmutable())->modify(sprintf('-%d days', $logDays));

            $stats['payloadsCleared'] += (int) $conn->executeStatement(
                'UPDATE form_webhook_log l
                 INNER JOIN form_webhook w ON w.id = l.form_webhook_id
                 SET l.raw_body = NULL, l.parsed_input = NULL
                 WHERE w.organization_id = :oid AND l.received_at < :before
                   AND (l.raw_body IS NOT NULL OR l.parsed_input IS NOT NULL)',
                ['oid' => $org->getId(), 'before' => $payloadBefore->format('Y-m-d H:i:s')],
            );

            $ids = $conn->fetchFirstColumn(
                'SELECT l.id FROM form_webhook_log l
                 INNER JOIN form_webhook w ON w.id = l.form_webhook_id
                 WHERE w.organization_id = :oid AND l.received_at < :before',
                ['oid' => $org->getId(), 'before' => $logBefore->format('Y-m-d H:i:s')],
            );
            if ($ids !== []) {
                $chunks = array_chunk(array_map('intval', $ids), 200);
                foreach ($chunks as $chunk) {
                    $in = implode(',', $chunk);
                    $conn->executeStatement("DELETE FROM form_webhook_action_log WHERE form_webhook_log_id IN ($in)");
                    $stats['logsDeleted'] += (int) $conn->executeStatement("DELETE FROM form_webhook_log WHERE id IN ($in)");
                }
            }
        }

        $stats['alertsPurged'] = $this->alertRepository->purgeResolvedBefore(new \DateTimeImmutable('-90 days'));
        $stats['metricsPurged'] += $this->metricAggRepository->purgeOlderThan(
            MonitoringMetricAgg::PERIOD_HOUR,
            new \DateTimeImmutable('-40 days'),
        );
        $stats['metricsPurged'] += $this->metricAggRepository->purgeOlderThan(
            MonitoringMetricAgg::PERIOD_MINUTE,
            new \DateTimeImmutable('-40 days'),
        );
        $stats['metricsPurged'] += $this->metricAggRepository->purgeOlderThan(
            MonitoringMetricAgg::PERIOD_DAY,
            new \DateTimeImmutable('-400 days'),
        );

        return $stats;
    }

    /**
     * @return array{0: int, 1: int} payloadDays, logDays
     */
    private function retentionDays(SubscriptionPlan $plan): array
    {
        return match ($plan) {
            SubscriptionPlan::Free => [7, 30],
            SubscriptionPlan::Starter => [30, 90],
            SubscriptionPlan::Pro => [90, 365],
        };
    }
}
