<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringCostEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringCostEntryRepository::class)]
#[ORM\Table(name: 'monitoring_cost_entry')]
#[ORM\UniqueConstraint(name: 'UNIQ_mon_cost_day', columns: ['period_day', 'organization_id', 'channel', 'provider'])]
#[ORM\Index(name: 'IDX_mon_cost_day', columns: ['period_day'])]
class MonitoringCostEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $periodDay;

    #[ORM\Column(nullable: true)]
    private ?int $organizationId = null;

    #[ORM\Column(length: 32)]
    private string $channel = '';

    #[ORM\Column(length: 64)]
    private string $provider = '';

    #[ORM\Column]
    private float $units = 0.0;

    #[ORM\Column]
    private int $costCents = 0;

    #[ORM\Column(length: 3)]
    private string $currency = 'EUR';

    #[ORM\Column(nullable: true)]
    private ?int $pricingRuleId = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $meta = null;

    public function __construct()
    {
        $this->periodDay = new \DateTimeImmutable('today');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPeriodDay(): \DateTimeImmutable
    {
        return $this->periodDay;
    }

    public function setPeriodDay(\DateTimeImmutable $periodDay): static
    {
        $this->periodDay = $periodDay;

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

    public function getChannel(): string
    {
        return $this->channel;
    }

    public function setChannel(string $channel): static
    {
        $this->channel = $channel;

        return $this;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): static
    {
        $this->provider = $provider;

        return $this;
    }

    public function getUnits(): float
    {
        return $this->units;
    }

    public function setUnits(float $units): static
    {
        $this->units = $units;

        return $this;
    }

    public function getCostCents(): int
    {
        return $this->costCents;
    }

    public function setCostCents(int $costCents): static
    {
        $this->costCents = $costCents;

        return $this;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function setCurrency(string $currency): static
    {
        $this->currency = $currency;

        return $this;
    }

    public function getPricingRuleId(): ?int
    {
        return $this->pricingRuleId;
    }

    public function setPricingRuleId(?int $pricingRuleId): static
    {
        $this->pricingRuleId = $pricingRuleId;

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getMeta(): ?array
    {
        return $this->meta;
    }

    /** @param array<string, mixed>|null $meta */
    public function setMeta(?array $meta): static
    {
        $this->meta = $meta;

        return $this;
    }
}
