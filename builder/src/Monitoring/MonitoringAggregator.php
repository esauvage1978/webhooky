<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\MonitoringMetricAgg;
use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookLogRepository;
use App\Repository\MonitoringMetricAggRepository;
use Doctrine\ORM\EntityManagerInterface;

final class MonitoringAggregator
{
    public function __construct(
        private readonly FormWebhookLogRepository $logRepository,
        private readonly MonitoringMetricAggRepository $metricAggRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function aggregateFromLogs(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        $rows = $this->logRepository->createQueryBuilder('l')
            ->select('l.id, l.status, l.durationMs, l.receivedAt, IDENTITY(w.organization) AS orgId')
            ->join('l.formWebhook', 'w')
            ->andWhere('l.receivedAt >= :from')
            ->andWhere('l.receivedAt < :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getArrayResult();

        $touched = 0;
        $buckets = [];
        foreach ($rows as $row) {
            /** @var \DateTimeImmutable $at */
            $at = $row['receivedAt'];
            $hour = $at->setTime((int) $at->format('H'), 0, 0);
            $orgId = $row['orgId'] !== null ? (int) $row['orgId'] : null;
            $hk = $hour->format('c').'|'.($orgId ?? 'g');
            if (!isset($buckets[$hk])) {
                $buckets[$hk] = [
                    'hour' => $hour,
                    'orgId' => $orgId,
                    'received' => 0,
                    'success' => 0,
                    'error' => 0,
                    'skipped' => 0,
                    'retry' => 0,
                    'dead' => 0,
                    'durSum' => 0.0,
                    'durCount' => 0,
                    'durMax' => null,
                ];
            }
            ++$buckets[$hk]['received'];
            $st = (string) $row['status'];
            match ($st) {
                FormWebhookLogStatus::SENT => ++$buckets[$hk]['success'],
                FormWebhookLogStatus::ERROR => ++$buckets[$hk]['error'],
                FormWebhookLogStatus::SKIPPED => ++$buckets[$hk]['skipped'],
                FormWebhookLogStatus::RETRY_SCHEDULED => ++$buckets[$hk]['retry'],
                FormWebhookLogStatus::DEAD_LETTER => ++$buckets[$hk]['dead'],
                default => null,
            };
            if ($row['durationMs'] !== null) {
                $d = (float) $row['durationMs'];
                $buckets[$hk]['durSum'] += $d;
                ++$buckets[$hk]['durCount'];
                $buckets[$hk]['durMax'] = $buckets[$hk]['durMax'] === null ? $d : max($buckets[$hk]['durMax'], $d);
            }
        }

        foreach ($buckets as $b) {
            $hour = $b['hour'];
            $orgId = $b['orgId'];
            $touched += $this->writeCount($hour, MonitoringMetricKeys::WEBHOOK_RECEIVED, $orgId, (float) $b['received']);
            $touched += $this->writeCount($hour, MonitoringMetricKeys::WEBHOOK_RUN_SUCCESS, $orgId, (float) $b['success']);
            $touched += $this->writeCount($hour, MonitoringMetricKeys::WEBHOOK_RUN_ERROR, $orgId, (float) $b['error']);
            $touched += $this->writeCount($hour, MonitoringMetricKeys::WEBHOOK_RUN_SKIPPED, $orgId, (float) $b['skipped']);
            if ($b['retry'] > 0) {
                $touched += $this->writeCount($hour, MonitoringMetricKeys::WEBHOOK_RETRY_SCHEDULED, $orgId, (float) $b['retry']);
            }
            if ($b['dead'] > 0) {
                $touched += $this->writeCount($hour, MonitoringMetricKeys::WEBHOOK_DEAD_LETTER, $orgId, (float) $b['dead']);
            }
            if ($b['durCount'] > 0) {
                $this->metricAggRepository->upsertAdd(
                    MonitoringMetricAgg::PERIOD_HOUR,
                    $hour,
                    MonitoringMetricKeys::WEBHOOK_DURATION_MS,
                    $orgId,
                    [],
                    (float) $b['durSum'],
                    (int) $b['durCount'],
                    $b['durMax'],
                );
                ++$touched;
            }
        }

        $actionRows = $this->entityManager->getConnection()->fetchAllAssociative(
            'SELECT al.status, al.duration_ms, al.mailjet_http_status AS http_status, l.received_at, w.organization_id AS org_id, fa.action_type
             FROM form_webhook_action_log al
             INNER JOIN form_webhook_log l ON l.id = al.form_webhook_log_id
             INNER JOIN form_webhook w ON w.id = l.form_webhook_id
             LEFT JOIN form_webhook_action fa ON fa.id = al.form_webhook_action_id
             WHERE l.received_at >= :from AND l.received_at < :to',
            ['from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s')],
        );

        $actionBuckets = [];
        foreach ($actionRows as $row) {
            $at = new \DateTimeImmutable((string) $row['received_at']);
            $hour = $at->setTime((int) $at->format('H'), 0, 0);
            $orgId = $row['org_id'] !== null ? (int) $row['org_id'] : null;
            $type = (string) ($row['action_type'] ?? 'unknown');
            $hk = $hour->format('c').'|'.($orgId ?? 'g').'|'.$type;
            if (!isset($actionBuckets[$hk])) {
                $actionBuckets[$hk] = [
                    'hour' => $hour,
                    'orgId' => $orgId,
                    'type' => $type,
                    'ok' => 0,
                    'err' => 0,
                    'durSum' => 0.0,
                    'durCount' => 0,
                    'http' => ['2xx' => 0, '4xx' => 0, '5xx' => 0, 'other' => 0],
                ];
            }
            $st = (string) $row['status'];
            if ($st === FormWebhookLogStatus::SENT) {
                ++$actionBuckets[$hk]['ok'];
            } elseif ($st === FormWebhookLogStatus::ERROR || $st === FormWebhookLogStatus::DEAD_LETTER) {
                ++$actionBuckets[$hk]['err'];
            }
            if ($row['duration_ms'] !== null) {
                $actionBuckets[$hk]['durSum'] += (float) $row['duration_ms'];
                ++$actionBuckets[$hk]['durCount'];
            }
            if ($row['http_status'] !== null) {
                $code = (int) $row['http_status'];
                $bucket = match (true) {
                    $code >= 200 && $code < 300 => '2xx',
                    $code >= 400 && $code < 500 => '4xx',
                    $code >= 500 => '5xx',
                    default => 'other',
                };
                ++$actionBuckets[$hk]['http'][$bucket];
            }
        }

        foreach ($actionBuckets as $b) {
            $dims = ['actionType' => $b['type']];
            if ($b['ok'] > 0) {
                $this->metricAggRepository->upsertAdd(
                    MonitoringMetricAgg::PERIOD_HOUR,
                    $b['hour'],
                    MonitoringMetricKeys::WEBHOOK_ACTION_SUCCESS,
                    $b['orgId'],
                    $dims,
                    (float) $b['ok'],
                    (int) $b['ok'],
                    null,
                );
                ++$touched;
            }
            if ($b['err'] > 0) {
                $this->metricAggRepository->upsertAdd(
                    MonitoringMetricAgg::PERIOD_HOUR,
                    $b['hour'],
                    MonitoringMetricKeys::WEBHOOK_ACTION_ERROR,
                    $b['orgId'],
                    $dims,
                    (float) $b['err'],
                    (int) $b['err'],
                    null,
                );
                ++$touched;
            }
            foreach ($b['http'] as $bucket => $cnt) {
                if ($cnt <= 0) {
                    continue;
                }
                $this->metricAggRepository->upsertAdd(
                    MonitoringMetricAgg::PERIOD_HOUR,
                    $b['hour'],
                    MonitoringMetricKeys::WEBHOOK_HTTP_STATUS,
                    $b['orgId'],
                    $dims + ['bucket' => $bucket],
                    (float) $cnt,
                    (int) $cnt,
                    null,
                );
                ++$touched;
            }
        }

        $this->entityManager->flush();

        return $touched;
    }

    private function writeCount(\DateTimeImmutable $hour, string $key, ?int $orgId, float $count): int
    {
        if ($count <= 0) {
            return 0;
        }
        $this->metricAggRepository->upsertAdd(
            MonitoringMetricAgg::PERIOD_HOUR,
            $hour,
            $key,
            $orgId,
            [],
            $count,
            (int) $count,
            null,
        );

        return 1;
    }
}
