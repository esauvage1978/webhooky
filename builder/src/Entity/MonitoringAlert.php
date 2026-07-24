<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringAlertRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringAlertRepository::class)]
#[ORM\Table(name: 'monitoring_alert')]
#[ORM\UniqueConstraint(name: 'UNIQ_mon_alert_fp', columns: ['fingerprint'])]
#[ORM\Index(name: 'IDX_mon_alert_status', columns: ['status', 'severity', 'last_seen_at'])]
class MonitoringAlert
{
    public const STATUS_OPEN = 'open';
    public const STATUS_ACKNOWLEDGED = 'acknowledged';
    public const STATUS_RESOLVED = 'resolved';

    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    private string $code = '';

    #[ORM\Column(length: 64)]
    private string $domain = '';

    #[ORM\Column(length: 16)]
    private string $severity = self::SEVERITY_WARNING;

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $message = '';

    #[ORM\Column(nullable: true)]
    private ?int $organizationId = null;

    #[ORM\Column(length: 128)]
    private string $fingerprint = '';

    #[ORM\Column(length: 16)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $firstSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastSeenAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column]
    private int $occurrenceCount = 1;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $context = null;

    public function __construct()
    {
        $now = new \DateTimeImmutable();
        $this->firstSeenAt = $now;
        $this->lastSeenAt = $now;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): static
    {
        $this->domain = $domain;

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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setMessage(string $message): static
    {
        $this->message = $message;

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

    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    public function setFingerprint(string $fingerprint): static
    {
        $this->fingerprint = $fingerprint;

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

    public function getFirstSeenAt(): \DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function setFirstSeenAt(\DateTimeImmutable $firstSeenAt): static
    {
        $this->firstSeenAt = $firstSeenAt;

        return $this;
    }

    public function getLastSeenAt(): \DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function setLastSeenAt(\DateTimeImmutable $lastSeenAt): static
    {
        $this->lastSeenAt = $lastSeenAt;

        return $this;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function setAcknowledgedAt(?\DateTimeImmutable $acknowledgedAt): static
    {
        $this->acknowledgedAt = $acknowledgedAt;

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

    public function getOccurrenceCount(): int
    {
        return $this->occurrenceCount;
    }

    public function setOccurrenceCount(int $occurrenceCount): static
    {
        $this->occurrenceCount = $occurrenceCount;

        return $this;
    }

    public function bumpOccurrence(): static
    {
        ++$this->occurrenceCount;
        $this->lastSeenAt = new \DateTimeImmutable();
        if ($this->status === self::STATUS_RESOLVED) {
            $this->status = self::STATUS_OPEN;
            $this->resolvedAt = null;
        }

        return $this;
    }

    /** @return array<string, mixed>|null */
    public function getContext(): ?array
    {
        return $this->context;
    }

    /** @param array<string, mixed>|null $context */
    public function setContext(?array $context): static
    {
        $this->context = $context;

        return $this;
    }
}
