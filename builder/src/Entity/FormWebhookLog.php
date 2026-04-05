<?php

declare(strict_types=1);

namespace App\Entity;

use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookLogRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Trace d’un ingress (requête brute, parse global). Détail Mailjet par action : FormWebhookActionLog.
 */
#[ORM\Entity(repositoryClass: FormWebhookLogRepository::class)]
#[ORM\Table(name: 'form_webhook_log')]
#[ORM\Index(name: 'IDX_form_webhook_log_webhook_received', columns: ['form_webhook_id', 'received_at'])]
class FormWebhookLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FormWebhook::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormWebhook $formWebhook = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $receivedAt = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $clientIp = null;

    #[ORM\Column(length: 512, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $contentType = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $rawBody = null;

    /** Données extraites avant mapping (parser). */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $parsedInput = null;

    #[ORM\Column(length: 32)]
    private string $status = FormWebhookLogStatus::RECEIVED;

    /** Synthèse ou première erreur ; le détail par action est dans actionLogs. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorDetail = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /**
     * @var Collection<int, FormWebhookActionLog>
     */
    #[ORM\OneToMany(targetEntity: FormWebhookActionLog::class, mappedBy: 'formWebhookLog', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $actionLogs;

    public function __construct()
    {
        $this->receivedAt = new \DateTimeImmutable();
        $this->actionLogs = new ArrayCollection();
    }

    public function addActionLog(FormWebhookActionLog $log): static
    {
        if (!$this->actionLogs->contains($log)) {
            $this->actionLogs->add($log);
            $log->setFormWebhookLog($this);
        }

        return $this;
    }

    /**
     * @return Collection<int, FormWebhookActionLog>
     */
    public function getActionLogs(): Collection
    {
        return $this->actionLogs;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormWebhook(): ?FormWebhook
    {
        return $this->formWebhook;
    }

    public function setFormWebhook(?FormWebhook $formWebhook): static
    {
        $this->formWebhook = $formWebhook;

        return $this;
    }

    public function getReceivedAt(): ?\DateTimeImmutable
    {
        return $this->receivedAt;
    }

    public function setReceivedAt(\DateTimeImmutable $receivedAt): static
    {
        $this->receivedAt = $receivedAt;

        return $this;
    }

    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    public function setClientIp(?string $clientIp): static
    {
        $this->clientIp = $clientIp;

        return $this;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    public function setUserAgent(?string $userAgent): static
    {
        $this->userAgent = $userAgent;

        return $this;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function setContentType(?string $contentType): static
    {
        $this->contentType = $contentType;

        return $this;
    }

    public function getRawBody(): ?string
    {
        return $this->rawBody;
    }

    public function setRawBody(?string $rawBody): static
    {
        $this->rawBody = $rawBody;

        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getParsedInput(): ?array
    {
        return $this->parsedInput;
    }

    /**
     * @param array<string, string>|null $parsedInput
     */
    public function setParsedInput(?array $parsedInput): static
    {
        $this->parsedInput = $parsedInput;

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

    public function getErrorDetail(): ?string
    {
        return $this->errorDetail;
    }

    public function setErrorDetail(?string $errorDetail): static
    {
        $this->errorDetail = $errorDetail;

        return $this;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    public function setDurationMs(?int $durationMs): static
    {
        $this->durationMs = $durationMs;

        return $this;
    }
}
