<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringMetricAggRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringMetricAggRepository::class)]
#[ORM\Table(name: 'monitoring_metric_agg')]
#[ORM\UniqueConstraint(name: 'UNIQ_mon_metric_agg', columns: ['period_type', 'period_start', 'metric_key', 'organization_id', 'dimension_hash'])]
#[ORM\Index(name: 'IDX_mon_metric_agg_lookup', columns: ['period_type', 'period_start', 'metric_key'])]
class MonitoringMetricAgg
{
    public const PERIOD_MINUTE = 'minute';
    public const PERIOD_HOUR = 'hour';
    public const PERIOD_DAY = 'day';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 16)]
    private string $periodType = self::PERIOD_HOUR;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $periodStart;

    #[ORM\Column(length: 96)]
    private string $metricKey = '';

    #[ORM\Column(nullable: true)]
    private ?int $organizationId = null;

    #[ORM\Column(length: 64)]
    private string $dimensionHash = '';

    /** @var array<string, scalar>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $dimensions = null;

    #[ORM\Column]
    private float $valueSum = 0.0;

    #[ORM\Column]
    private int $valueCount = 0;

    #[ORM\Column(nullable: true)]
    private ?float $valueMax = null;

    public function __construct()
    {
        $this->periodStart = new \DateTimeImmutable('@0');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriodType(): string
    {
        return $this->periodType;
    }

    public function setPeriodType(string $periodType): static
    {
        $this->periodType = $periodType;

        return $this;
    }

    public function getPeriodStart(): \DateTimeImmutable
    {
        return $this->periodStart;
    }

    public function setPeriodStart(\DateTimeImmutable $periodStart): static
    {
        $this->periodStart = $periodStart;

        return $this;
    }

    public function getMetricKey(): string
    {
        return $this->metricKey;
    }

    public function setMetricKey(string $metricKey): static
    {
        $this->metricKey = $metricKey;

        return $this;
    }

    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    public function setOrganizationId(?int $organizationId): static
    {
        $this->organizationId = $organizationId;

        return $this;
    }

    public function getDimensionHash(): string
    {
        return $this->dimensionHash;
    }

    public function setDimensionHash(string $dimensionHash): static
    {
        $this->dimensionHash = $dimensionHash;

        return $this;
    }

    /** @return array<string, scalar>|null */
    public function getDimensions(): ?array
    {
        return $this->dimensions;
    }

    /** @param array<string, scalar>|null $dimensions */
    public function setDimensions(?array $dimensions): static
    {
        $this->dimensions = $dimensions;

        return $this;
    }

    public function getValueSum(): float
    {
        return $this->valueSum;
    }

    public function setValueSum(float $valueSum): static
    {
        $this->valueSum = $valueSum;

        return $this;
    }

    public function getValueCount(): int
    {
        return $this->valueCount;
    }

    public function setValueCount(int $valueCount): static
    {
        $this->valueCount = $valueCount;

        return $this;
    }

    public function getValueMax(): ?float
    {
        return $this->valueMax;
    }

    public function setValueMax(?float $valueMax): static
    {
        $this->valueMax = $valueMax;

        return $this;
    }
}
