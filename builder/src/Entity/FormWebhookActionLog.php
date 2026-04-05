<?php

declare(strict_types=1);

namespace App\Entity;

use App\FormWebhook\FormWebhookLogStatus;
use App\Repository\FormWebhookActionLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal d’exécution d’une action pour un ingress donné.
 */
#[ORM\Entity(repositoryClass: FormWebhookActionLogRepository::class)]
#[ORM\Table(name: 'form_webhook_action_log')]
#[ORM\Index(name: 'IDX_form_webhook_action_log_parent', columns: ['form_webhook_log_id', 'sort_order'])]
class FormWebhookActionLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FormWebhookLog::class, inversedBy: 'actionLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormWebhookLog $formWebhookLog = null;

    #[ORM\ManyToOne(targetEntity: FormWebhookAction::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?FormWebhookAction $formWebhookAction = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $variablesSent = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $toEmail = null;

    #[ORM\Column(length: 32)]
    private string $status = FormWebhookLogStatus::RECEIVED;

    #[ORM\Column(nullable: true)]
    private ?int $mailjetHttpStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $mailjetResponseBody = null;

    #[ORM\Column(length: 64, nullable: true)]
    private ?string $mailjetMessageId = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $errorDetail = null;

    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFormWebhookLog(): ?FormWebhookLog
    {
        return $this->formWebhookLog;
    }

    public function setFormWebhookLog(?FormWebhookLog $formWebhookLog): static
    {
        $this->formWebhookLog = $formWebhookLog;

        return $this;
    }

    public function getFormWebhookAction(): ?FormWebhookAction
    {
        return $this->formWebhookAction;
    }

    public function setFormWebhookAction(?FormWebhookAction $formWebhookAction): static
    {
        $this->formWebhookAction = $formWebhookAction;

        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    /**
     * @return array<string, string>|null
     */
    public function getVariablesSent(): ?array
    {
        return $this->variablesSent;
    }

    /**
     * @param array<string, string>|null $variablesSent
     */
    public function setVariablesSent(?array $variablesSent): static
    {
        $this->variablesSent = $variablesSent;

        return $this;
    }

    public function getToEmail(): ?string
    {
        return $this->toEmail;
    }

    public function setToEmail(?string $toEmail): static
    {
        $this->toEmail = $toEmail;

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

    public function getMailjetHttpStatus(): ?int
    {
        return $this->mailjetHttpStatus;
    }

    public function setMailjetHttpStatus(?int $mailjetHttpStatus): static
    {
        $this->mailjetHttpStatus = $mailjetHttpStatus;

        return $this;
    }

    public function getMailjetResponseBody(): ?string
    {
        return $this->mailjetResponseBody;
    }

    public function setMailjetResponseBody(?string $mailjetResponseBody): static
    {
        $this->mailjetResponseBody = $mailjetResponseBody;

        return $this;
    }

    public function getMailjetMessageId(): ?string
    {
        return $this->mailjetMessageId;
    }

    public function setMailjetMessageId(?string $mailjetMessageId): static
    {
        $this->mailjetMessageId = $mailjetMessageId;

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
