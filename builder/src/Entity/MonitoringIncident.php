<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringIncidentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringIncidentRepository::class)]
#[ORM\Table(name: 'monitoring_incident')]
#[ORM\Index(name: 'IDX_mon_incident_status', columns: ['status', 'opened_at'])]
class MonitoringIncident
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(length: 16)]
    private string $severity = MonitoringAlert::SEVERITY_WARNING;

    #[ORM\Column(nullable: true)]
    private ?int $organizationId = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $openedAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summary = null;

    /** @var list<int>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $alertIds = null;

    public function __construct()
    {
        $this->openedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getSeverity(): string
    {
        return $this->severity;
    }

    public function setSeverity(string $severity): static
    {
        $this->severity = $severity;

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

    public function getOpenedAt(): \DateTimeImmutable
    {
        return $this->openedAt;
    }

    public function setOpenedAt(\DateTimeImmutable $openedAt): static
    {
        $this->openedAt = $openedAt;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getSummary(): ?string
    {
        return $this->summary;
    }

    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /** @return list<int>|null */
    public function getAlertIds(): ?array
    {
        return $this->alertIds;
    }

    /** @param list<int>|null $alertIds */
    public function setAlertIds(?array $alertIds): static
    {
        $this->alertIds = $alertIds;

        return $this;
    }
}
