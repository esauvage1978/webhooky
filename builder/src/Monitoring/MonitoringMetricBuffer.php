<?php

declare(strict_types=1);

namespace App\Monitoring;

use App\Entity\MonitoringMetricAgg;
use App\Repository\MonitoringMetricAggRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Buffer métriques best-effort : panne monitoring ≠ panne webhook.
 */
final class MonitoringMetricBuffer
{
    /** @var list<array{key: string, value: float, orgId: ?int, dims: array<string, scalar>}> */
    private array $pending = [];

    public function __construct(
        private readonly MonitoringMetricAggRepository $metricAggRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @param array<string, scalar> $dims
     */
    public function increment(string $key, float $value = 1.0, ?int $orgId = null, array $dims = []): void
    {
        try {
            $this->pending[] = ['key' => $key, 'value' => $value, 'orgId' => $orgId, 'dims' => $dims];
            if (\count($this->pending) >= 40) {
                $this->flushToDatabase();
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('monitoring.buffer.increment_failed', ['error' => $e->getMessage()]);
            $this->pending = [];
        }
    }

    public function flushToDatabase(): void
    {
        if ($this->pending === []) {
            return;
        }
        try {
            $bucket = $this->currentHourBucket();
            $merged = [];
            foreach ($this->pending as $row) {
                $hash = MonitoringMetricAggRepository::hashDimensions($row['dims']);
                $k = $row['key'].'|'.($row['orgId'] ?? 'g').'|'.$hash;
                if (!isset($merged[$k])) {
                    $merged[$k] = $row + ['count' => 0, 'sum' => 0.0, 'max' => null];
                }
                $merged[$k]['sum'] += $row['value'];
                ++$merged[$k]['count'];
                $merged[$k]['max'] = $merged[$k]['max'] === null ? $row['value'] : max($merged[$k]['max'], $row['value']);
            }
            foreach ($merged as $row) {
                $this->metricAggRepository->upsertAdd(
                    MonitoringMetricAgg::PERIOD_HOUR,
                    $bucket,
                    $row['key'],
                    $row['orgId'],
                    $row['dims'],
                    (float) $row['sum'],
                    (int) $row['count'],
                    $row['max'] !== null ? (float) $row['max'] : null,
                );
            }
            $this->entityManager->flush();
            $this->pending = [];
        } catch (\Throwable $e) {
            $this->logger?->warning('monitoring.buffer.flush_failed', ['error' => $e->getMessage()]);
            $this->pending = [];
            try {
                if ($this->entityManager->getConnection()->isTransactionActive()) {
                    $this->entityManager->clear();
                }
            } catch (\Throwable) {
            }
        }
    }

    private function currentHourBucket(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now');

        return $now->setTime((int) $now->format('H'), 0, 0);
    }
}
