<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\MonitoringAlert;
use App\Entity\MonitoringIncident;
use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookLogRepository;
use App\Repository\MonitoringAlertRepository;
use App\Repository\MonitoringCostEntryRepository;
use App\Repository\MonitoringIncidentRepository;
use App\Repository\PricingRuleRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MonitoringOverviewBuilder
{
    public function __construct(
        private readonly FormWebhookLogRepository $logRepository,
        private readonly WebhookyHealthScoreCalculator $healthScoreCalculator,
        private readonly MonitoringAlertRepository $alertRepository,
        private readonly MonitoringIncidentRepository $incidentRepository,
        private readonly MonitoringCostEntryRepository $costEntryRepository,
        private readonly PricingRuleRepository $pricingRuleRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAdmin(string $period = '24h'): array
    {
        return $this->build(null, $period);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildClient(int $organizationId, string $period = '24h'): array
    {
        return $this->build($organizationId, $period);
    }

    /**
     * @return array<string, mixed>
     */
    private function build(?int $organizationId, string $period): array
    {
        [$from, $to] = $this->resolvePeriod($period);
        $counts = $this->logRepository->countByStatusBetween($from, $to, $organizationId);
        $received = array_sum($counts);
        $success = (int) ($counts[FormWebhookLogStatus::SENT] ?? 0);
        $error = (int) ($counts[FormWebhookLogStatus::ERROR] ?? 0);
        $skipped = (int) ($counts[FormWebhookLogStatus::SKIPPED] ?? 0);
        $retry = (int) ($counts[FormWebhookLogStatus::RETRY_SCHEDULED] ?? 0);
        $dead = (int) ($counts[FormWebhookLogStatus::DEAD_LETTER] ?? 0);
        $den = $success + $error + $retry + $dead;
        $dur = $this->logRepository->durationStatsBetween($from, $to, $organizationId);
        $rateLimited = $this->sumMetric(MonitoringMetricKeys::WEBHOOK_RATE_LIMITED, $from, $to, $organizationId);

        $domains = $this->domainStats($from, $to, $organizationId);
        $queue = $this->queueSnapshot();
        $costs = $this->costSnapshot($from, $to, $organizationId);

        $health = $this->healthScoreCalculator->calculate($organizationId, $from);

        return [
            'period' => $period,
            'from' => $from->format(\DateTimeInterface::ATOM),
            'to' => $to->format(\DateTimeInterface::ATOM),
            'health' => $health,
            'kpis' => [
                'received' => $received,
                'success' => $success,
                'error' => $error,
                'skipped' => $skipped,
                'retryScheduled' => $retry,
                'deadLetter' => $dead,
                'successRate' => $den > 0 ? round($success / $den, 4) : null,
                'avgDurationMs' => $dur['avg'],
                'p95DurationMs' => $dur['p95'],
                'rateLimited' => $rateLimited,
                'openAlerts' => $this->alertRepository->countOpen($organizationId),
                'openIncidents' => $this->incidentRepository->countOpen($organizationId),
            ],
            'pipeline' => $this->pipeline($received, $success, $error, $skipped, $retry, $dead, $domains, $queue),
            'series' => [
                'hourly' => $this->hourlySeries($from, $to, $organizationId),
            ],
            'domains' => $domains,
            'queue' => $queue,
            'costs' => $costs,
            'recentAlerts' => array_map([$this, 'serializeAlert'], $this->alertRepository->findRecentOpen(8, $organizationId)),
            'recentIncidents' => array_map([$this, 'serializeIncident'], $this->incidentRepository->findRecent(5, $organizationId)),
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function resolvePeriod(string $period): array
    {
        $to = new \DateTimeImmutable('now');
        $from = match ($period) {
            '1h' => $to->modify('-1 hour'),
            '7d' => $to->modify('-7 days'),
            '30d' => $to->modify('-30 days'),
            default => $to->modify('-24 hours'),
        };

        return [$from, $to];
    }

    /**
     * @param array<string, array{sent: int, error: int}> $domains
     * @param array<string, mixed> $queue
     *
     * @return list<array{id: string, label: string, status: string, count: int|null, detail: string}>
     */
    private function pipeline(
        int $received,
        int $success,
        int $error,
        int $skipped,
        int $retry,
        int $dead,
        array $domains,
        array $queue,
    ): array {
        $parseError = $error; // approx: includes parse + action failures
        $txSent = array_sum(array_column($domains, 'sent'));
        $txErr = array_sum(array_column($domains, 'error'));

        return [
            ['id' => 'reception', 'label' => 'Réception', 'status' => $received > 0 ? 'ok' : 'unknown', 'count' => $received, 'detail' => 'Ingress HTTP'],
            ['id' => 'validation', 'label' => 'Validation / parse', 'status' => $received === 0 ? 'unknown' : ($parseError > $success ? 'warn' : 'ok'), 'count' => max(0, $received - $skipped), 'detail' => 'PayloadParser'],
            ['id' => 'identification', 'label' => 'Identification source', 'status' => $received > 0 ? 'ok' : 'unknown', 'count' => $received, 'detail' => 'Workflow + organisation'],
            ['id' => 'actions', 'label' => 'Actions du workflow', 'status' => $txErr > 0 ? 'warn' : ($txSent > 0 ? 'ok' : 'unknown'), 'count' => $txSent + $txErr, 'detail' => 'Pas de moteur de règles séparé'],
            ['id' => 'queue', 'label' => 'Mise en file', 'status' => ($queue['applicable'] ?? false) ? (($queue['pending'] ?? 0) > 100 ? 'warn' : 'ok') : 'na', 'count' => $queue['pending'] ?? null, 'detail' => ($queue['applicable'] ?? false) ? 'Messenger async (retries)' : 'Non applicable (sync)'],
            ['id' => 'transmission', 'label' => 'Transmission', 'status' => $txErr > 0 ? 'error' : ($txSent > 0 ? 'ok' : 'unknown'), 'count' => $txSent, 'detail' => 'Mailjet / SMS / HTTP / builtins'],
            ['id' => 'confirmation', 'label' => 'Confirmation', 'status' => $success > 0 ? 'ok' : ($error > 0 ? 'error' : 'unknown'), 'count' => $success, 'detail' => 'Statut sent'],
            ['id' => 'retry', 'label' => 'Retry / DLQ', 'status' => $dead > 0 ? 'error' : ($retry > 0 ? 'warn' : ($queue['applicable'] ? 'ok' : 'na')), 'count' => $retry + $dead, 'detail' => sprintf('%d retry, %d dead letter', $retry, $dead)],
        ];
    }

    /**
     * @return array<string, array{sent: int, error: int}>
     */
    private function domainStats(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $organizationId): array
    {
        $sql = 'SELECT fa.action_type, al.status, COUNT(*) AS c
            FROM form_webhook_action_log al
            INNER JOIN form_webhook_log l ON l.id = al.form_webhook_log_id
            INNER JOIN form_webhook w ON w.id = l.form_webhook_id
            LEFT JOIN form_webhook_action fa ON fa.id = al.form_webhook_action_id
            WHERE l.received_at >= :from AND l.received_at < :to';
        $params = ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')];
        if ($organizationId !== null) {
            $sql .= ' AND w.organization_id = :oid';
            $params['oid'] = $organizationId;
        }
        $sql .= ' GROUP BY fa.action_type, al.status';
        $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, $params);
        $domains = [
            'email' => ['sent' => 0, 'error' => 0],
            'sms' => ['sent' => 0, 'error' => 0],
            'http' => ['sent' => 0, 'error' => 0],
            'builtin' => ['sent' => 0, 'error' => 0],
            'other' => ['sent' => 0, 'error' => 0],
        ];
        foreach ($rows as $row) {
            $type = (string) ($row['action_type'] ?? '');
            $dom = match (true) {
                $type === 'mailjet' => 'email',
                str_contains($type, 'sms') => 'sms',
                str_starts_with($type, 'http') || str_contains($type, 'webhook') => 'http',
                str_starts_with($type, 'ai_') || str_starts_with($type, 'gsc_') || str_starts_with($type, 'parse_') || str_starts_with($type, 'if_') => 'builtin',
                default => 'other',
            };
            $bucket = \in_array($row['status'], [FormWebhookLogStatus::ERROR, FormWebhookLogStatus::DEAD_LETTER], true) ? 'error' : 'sent';
            if ($row['status'] === FormWebhookLogStatus::SKIPPED) {
                continue;
            }
            if ($row['status'] !== FormWebhookLogStatus::SENT && $bucket !== 'error') {
                continue;
            }
            $domains[$dom][$bucket] += (int) $row['c'];
        }

        return $domains;
    }

    /**
     * @return list<array{t: string, received: int, success: int, error: int}>
     */
    private function hourlySeries(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $organizationId): array
    {
        $sql = 'SELECT DATE_FORMAT(l.received_at, \'%Y-%m-%d %H:00:00\') AS bucket,
                SUM(CASE WHEN 1=1 THEN 1 ELSE 0 END) AS received,
                SUM(CASE WHEN l.status = :sent THEN 1 ELSE 0 END) AS success,
                SUM(CASE WHEN l.status IN (:err, :dead) THEN 1 ELSE 0 END) AS error
            FROM form_webhook_log l
            INNER JOIN form_webhook w ON w.id = l.form_webhook_id
            WHERE l.received_at >= :from AND l.received_at < :to';
        $params = [
            'from' => $from->format('Y-m-d H:i:s'),
            'to' => $to->format('Y-m-d H:i:s'),
            'sent' => FormWebhookLogStatus::SENT,
            'err' => FormWebhookLogStatus::ERROR,
            'dead' => FormWebhookLogStatus::DEAD_LETTER,
        ];
        if ($organizationId !== null) {
            $sql .= ' AND w.organization_id = :oid';
            $params['oid'] = $organizationId;
        }
        $sql .= ' GROUP BY bucket ORDER BY bucket ASC';
        try {
            $rows = $this->entityManager->getConnection()->fetchAllAssociative($sql, $params);
        } catch (\Throwable) {
            // SQLite fallback
            $sqlLite = str_replace("DATE_FORMAT(l.received_at, '%Y-%m-%d %H:00:00')", "strftime('%Y-%m-%d %H:00:00', l.received_at)", $sql);
            $rows = $this->entityManager->getConnection()->fetchAllAssociative($sqlLite, $params);
        }

        return array_map(static fn (array $r) => [
            't' => (string) $r['bucket'],
            'received' => (int) $r['received'],
            'success' => (int) $r['success'],
            'error' => (int) $r['error'],
        ], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function queueSnapshot(): array
    {
        try {
            $conn = $this->entityManager->getConnection();
            $pending = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND delivered_at IS NULL",
            );
            $failed = (int) $conn->fetchOne(
                "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed'",
            );

            return [
                'applicable' => true,
                'pending' => $pending,
                'failed' => $failed,
                'note' => 'Transport Doctrine Messenger (retries actions)',
            ];
        } catch (\Throwable) {
            return [
                'applicable' => true,
                'pending' => null,
                'failed' => null,
                'note' => 'Table messenger_messages indisponible — lancer les migrations',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function costSnapshot(\DateTimeImmutable $from, \DateTimeImmutable $to, ?int $organizationId): array
    {
        $rules = $this->pricingRuleRepository->findActive();
        $configured = $rules !== [];
        $dayFrom = $from->setTime(0, 0);
        $dayTo = $to->setTime(0, 0);
        $entries = $configured ? $this->costEntryRepository->findForPeriod($dayFrom, $dayTo, $organizationId) : [];
        $byChannel = [];
        $total = 0;
        $currency = 'EUR';
        foreach ($entries as $e) {
            $ch = $e->getChannel();
            $byChannel[$ch] = ($byChannel[$ch] ?? 0) + $e->getCostCents();
            $total += $e->getCostCents();
            $currency = $e->getCurrency();
        }

        return [
            'configured' => $configured,
            'totalCents' => $configured ? $total : null,
            'currency' => $currency,
            'byChannel' => $configured ? $byChannel : [],
            'note' => $configured ? 'Estimations selon tarifs saisis manuellement' : 'Aucun tarif saisi — coûts non calculés',
        ];
    }

    private function sumMetric(string $key, \DateTimeImmutable $from, \DateTimeImmutable $to, ?int $organizationId): int
    {
        try {
            $sql = 'SELECT COALESCE(SUM(value_sum), 0) FROM monitoring_metric_agg
                WHERE metric_key = :k AND period_start >= :from AND period_start < :to';
            $params = [
                'k' => $key,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ];
            if ($organizationId !== null) {
                $sql .= ' AND organization_id = :oid';
                $params['oid'] = $organizationId;
            }

            return (int) $this->entityManager->getConnection()->fetchOne($sql, $params);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAlert(MonitoringAlert $a): array
    {
        return [
            'id' => $a->getId(),
            'code' => $a->getCode(),
            'domain' => $a->getDomain(),
            'severity' => $a->getSeverity(),
            'title' => $a->getTitle(),
            'message' => $a->getMessage(),
            'status' => $a->getStatus(),
            'organizationId' => $a->getOrganizationId(),
            'occurrenceCount' => $a->getOccurrenceCount(),
            'lastSeenAt' => $a->getLastSeenAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeIncident(MonitoringIncident $i): array
    {
        return [
            'id' => $i->getId(),
            'title' => $i->getTitle(),
            'status' => $i->getStatus(),
            'severity' => $i->getSeverity(),
            'organizationId' => $i->getOrganizationId(),
            'openedAt' => $i->getOpenedAt()->format(\DateTimeInterface::ATOM),
            'resolvedAt' => $i->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'summary' => $i->getSummary(),
            'alertIds' => $i->getAlertIds(),
        ];
    }
}
