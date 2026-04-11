<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationMonthlyEventUsageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur mensuel d’événements quota (unités facturées : une par action exécutée sur un ingress réussi).
 * Mis à jour en même temps que {@see Organization::eventsConsumed}.
 */
#[ORM\Entity(repositoryClass: OrganizationMonthlyEventUsageRepository::class)]
#[ORM\Table(name: 'organization_monthly_event_usage')]
#[ORM\UniqueConstraint(name: 'uniq_org_month_year', fields: ['organization', 'year', 'month'])]
class OrganizationMonthlyEventUsage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $year = 0;

    /** 1–12 */
    #[ORM\Column(type: Types::SMALLINT)]
    private int $month = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $eventCount = 0;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrganization(): ?Organization
    {
        return $this->organization;
    }

    public function setOrganization(?Organization $organization): static
    {
        $this->organization = $organization;

        return $this;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function setYear(int $year): static
    {
        $this->year = $year;

        return $this;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function setMonth(int $month): static
    {
        $this->month = $month;

        return $this;
    }

    public function getEventCount(): int
    {
        return $this->eventCount;
    }

    public function setEventCount(int $eventCount): static
    {
        $this->eventCount = max(0, $eventCount);

        return $this;
    }
}
