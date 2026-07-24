<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookLogRepository;
use App\Repository\MonitoringAlertRepository;
use App\Repository\MonitoringSettingRepository;
use App\Subscription\SubscriptionEntitlementService;
use Doctrine\ORM\EntityManagerInterface;

final class WebhookyHealthScoreCalculator
{
    public function __construct(
        private readonly FormWebhookLogRepository $logRepository,
        private readonly MonitoringAlertRepository $alertRepository,
        private readonly MonitoringSettingRepository $settingRepository,
        private readonly SubscriptionEntitlementService $entitlementService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array{
     *   score: int|null,
     *   status: string,
     *   factors: list<array{key: string, label: string, weight: float, value: float|null, contribution: float|null}>,
     *   computedAt: string,
     *   sampleSize: int
     * }
     */
    public function calculate(?int $organizationId = null, ?\DateTimeImmutable $since = null): array
    {
        $since ??= new \DateTimeImmutable('-1 hour');
        $thresholds = $this->settingRepository->getValue('health_thresholds', [
            'p95Ms' => 8000,
            'errorRateWarn' => 0.05,
            'errorRateCrit' => 0.15,
        ]);
        $p95Threshold = (float) ($thresholds['p95Ms'] ?? 8000);

        $counts = $this->logRepository->countByStatusBetween($since, new \DateTimeImmutable('now'), $organizationId);
        $received = array_sum($counts);
        $success = (int) ($counts[FormWebhookLogStatus::SENT] ?? 0);
        $error = (int) ($counts[FormWebhookLogStatus::ERROR] ?? 0) + (int) ($counts[FormWebhookLogStatus::DEAD_LETTER] ?? 0);
        $runDen = $success + $error + (int) ($counts[FormWebhookLogStatus::RETRY_SCHEDULED] ?? 0);
        $runRate = $runDen > 0 ? $success / $runDen : null;

        $actionStats = $this->actionSuccessRate($since, $organizationId);
        $dur = $this->logRepository->durationStatsBetween($since, new \DateTimeImmutable('now'), $organizationId);
        $p95 = $dur['p95'];
        $latencyOk = $p95 === null ? null : ($p95 <= $p95Threshold ? 1.0 : 0.0);

        $critical = $this->alertRepository->countOpenCritical($organizationId);
        $alertsOk = $critical === 0 ? 1.0 : 0.0;

        $quotaOk = $this->quotaFactor($organizationId);

        $factors = [
            ['key' => 'run_success_rate', 'label' => 'Taux succès runs', 'weight' => 0.35, 'value' => $runRate, 'contribution' => null],
            ['key' => 'action_success_rate', 'label' => 'Taux succès actions', 'weight' => 0.25, 'value' => $actionStats['rate'], 'contribution' => null],
            ['key' => 'latency_p95_ok', 'label' => 'Latence p95 sous seuil', 'weight' => 0.15, 'value' => $latencyOk, 'contribution' => null],
            ['key' => 'open_critical_alerts', 'label' => 'Pas d’alerte critique', 'weight' => 0.15, 'value' => $alertsOk, 'contribution' => null],
            ['key' => 'quota_pressure', 'label' => 'Marge sous pression', 'weight' => 0.10, 'value' => $quotaOk, 'contribution' => null],
        ];

        if ($received === 0 && $actionStats['total'] === 0) {
            return [
                'score' => null,
                'status' => 'unknown',
                'factors' => $factors,
                'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                'sampleSize' => 0,
            ];
        }

        $score = 0.0;
        $weightSum = 0.0;
        foreach ($factors as &$f) {
            if ($f['value'] === null) {
                continue;
            }
            $c = $f['weight'] * (float) $f['value'];
            $f['contribution'] = round($c * 100, 2);
            $score += $c;
            $weightSum += $f['weight'];
        }
        unset($f);

        $normalized = $weightSum > 0 ? (int) round(($score / $weightSum) * 100) : null;
        $errorRate = $runDen > 0 ? $error / $runDen : 0.0;
        $status = 'healthy';
        if ($normalized === null) {
            $status = 'unknown';
        } elseif ($critical > 0 || $errorRate >= (float) ($thresholds['errorRateCrit'] ?? 0.15) || ($normalized !== null && $normalized < 50)) {
            $status = 'critical';
        } elseif ($errorRate >= (float) ($thresholds['errorRateWarn'] ?? 0.05) || ($normalized !== null && $normalized < 80)) {
            $status = 'degraded';
        }

        return [
            'score' => $normalized,
            'status' => $status,
            'factors' => $factors,
            'computedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'sampleSize' => $received,
        ];
    }

    /**
     * @return array{rate: float|null, total: int}
     */
    private function actionSuccessRate(\DateTimeImmutable $since, ?int $organizationId): array
    {
        $sql = 'SELECT al.status, COUNT(*) AS c
            FROM form_webhook_action_log al
            INNER JOIN form_webhook_log l ON l.id = al.form_webhook_log_id
            INNER JOIN form_webhook w ON w.id = l.form_webhook_id
            WHERE l.received_at >= :since';
        $params = ['since' => $since->format('Y-m-d H:i:s')];
        if ($organizationId !== null) {
            $sql .= ' AND w.organization_id = :oid';
            $params['oid'] = $organizationId;
        }
        $sql .= ' GROUP BY al.status';
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, $params);
        $ok = 0;
        $err = 0;
        foreach ($rows as $r) {
            if ($r['status'] === FormWebhookLogStatus::SENT || $r['status'] === FormWebhookLogStatus::SKIPPED) {
                $ok += (int) $r['c'];
            } elseif (\in_array($r['status'], [FormWebhookLogStatus::ERROR, FormWebhookLogStatus::DEAD_LETTER], true)) {
                $err += (int) $r['c'];
            }
        }
        $total = $ok + $err;

        return ['rate' => $total > 0 ? $ok / $total : null, 'total' => $total];
    }

    private function quotaFactor(?int $organizationId): ?float
    {
        try {
            if ($organizationId !== null) {
                $org = $this->entityManager->find(\App\Entity\Organization::class, $organizationId);
                if ($org === null) {
                    return null;
                }
                $snap = $this->entitlementService->buildSnapshot($org);
                $used = (float) ($snap['eventsConsumed'] ?? 0);
                $cap = (float) ($snap['eventsAllowance'] ?? 0);
                if ($cap <= 0) {
                    return 1.0;
                }

                return ($used / $cap) < 0.95 ? 1.0 : 0.0;
            }

            // Admin : aucune org au-delà de 95 % → 1, sinon 0 (échantillon limité)
            $orgs = $this->entityManager->createQuery('SELECT o FROM App\Entity\Organization o ORDER BY o.id DESC')
                ->setMaxResults(50)
                ->getResult();
            foreach ($orgs as $org) {
                $snap = $this->entitlementService->buildSnapshot($org);
                $used = (float) ($snap['eventsConsumed'] ?? 0);
                $cap = (float) ($snap['eventsAllowance'] ?? 0);
                if ($cap > 0 && ($used / $cap) >= 0.95) {
                    return 0.0;
                }
            }

            return 1.0;
        } catch (\Throwable) {
            return null;
        }
    }
}
