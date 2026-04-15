<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormWebhookActionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Action exécutée après réception sur un déclencheur (Mailjet ou connecteur tiers).
 */
#[ORM\Entity(repositoryClass: FormWebhookActionRepository::class)]
#[ORM\Table(name: 'form_webhook_action')]
#[ORM\Index(name: 'IDX_form_webhook_action_webhook_sort', columns: ['form_webhook_id', 'sort_order'])]
class FormWebhookAction
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: FormWebhook::class, inversedBy: 'actions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?FormWebhook $formWebhook = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $sortOrder = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** @see \App\ServiceIntegration\ServiceIntegrationType */
    #[ORM\Column(length: 32, options: ['default' => 'mailjet'])]
    private string $actionType = 'mailjet';

    #[ORM\ManyToOne(targetEntity: Mailjet::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?Mailjet $mailjet = null;

    #[ORM\ManyToOne(targetEntity: ServiceConnection::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'RESTRICT')]
    private ?ServiceConnection $serviceConnection = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $mailjetTemplateId = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $templateLanguage = true;

    /**
     * @var array<string, string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $variableMapping = [];

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $recipientEmailPostKey = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $recipientNamePostKey = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $defaultRecipientEmail = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $payloadTemplate = null;

    #[ORM\Column(length: 128, nullable: true)]
    private ?string $smsToPostKey = null;

    #[ORM\Column(length: 48, nullable: true)]
    private ?string $smsToDefault = null;

    /** Note interne (équipe), non utilisée à l’exécution. */
    #[Assert\Length(max: 4000)]
    #[ORM\Column(name: 'action_comment', type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /**
     * Actions natives du pipeline SEO (gsc_fetch, ai_action, parse_json, if…).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $pipelineConfig = null;

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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getActionType(): string
    {
        return $this->actionType;
    }

    public function setActionType(string $actionType): static
    {
        $this->actionType = $actionType;

        return $this;
    }

    public function getServiceConnection(): ?ServiceConnection
    {
        return $this->serviceConnection;
    }

    public function setServiceConnection(?ServiceConnection $serviceConnection): static
    {
        $this->serviceConnection = $serviceConnection;

        return $this;
    }

    public function getMailjet(): ?Mailjet
    {
        return $this->mailjet;
    }

    public function setMailjet(?Mailjet $mailjet): static
    {
        $this->mailjet = $mailjet;

        return $this;
    }

    public function getMailjetTemplateId(): int
    {
        return $this->mailjetTemplateId;
    }

    public function setMailjetTemplateId(int $mailjetTemplateId): static
    {
        $this->mailjetTemplateId = $mailjetTemplateId;

        return $this;
    }

    public function isTemplateLanguage(): bool
    {
        return $this->templateLanguage;
    }

    public function setTemplateLanguage(bool $templateLanguage): static
    {
        $this->templateLanguage = $templateLanguage;

        return $this;
    }

    /**
     * @return array<string, string>
     */
    public function getVariableMapping(): array
    {
        return $this->variableMapping;
    }

    /**
     * @param array<string, string> $variableMapping
     */
    public function setVariableMapping(array $variableMapping): static
    {
        $this->variableMapping = $variableMapping;

        return $this;
    }

    public function getRecipientEmailPostKey(): ?string
    {
        return $this->recipientEmailPostKey;
    }

    public function setRecipientEmailPostKey(?string $recipientEmailPostKey): static
    {
        $this->recipientEmailPostKey = $recipientEmailPostKey;

        return $this;
    }

    public function getRecipientNamePostKey(): ?string
    {
        return $this->recipientNamePostKey;
    }

    public function setRecipientNamePostKey(?string $recipientNamePostKey): static
    {
        $this->recipientNamePostKey = $recipientNamePostKey;

        return $this;
    }

    public function getDefaultRecipientEmail(): ?string
    {
        return $this->defaultRecipientEmail;
    }

    public function setDefaultRecipientEmail(?string $defaultRecipientEmail): static
    {
        $this->defaultRecipientEmail = $defaultRecipientEmail;

        return $this;
    }

    public function getPayloadTemplate(): ?string
    {
        return $this->payloadTemplate;
    }

    public function setPayloadTemplate(?string $payloadTemplate): static
    {
        $this->payloadTemplate = $payloadTemplate;

        return $this;
    }

    public function getSmsToPostKey(): ?string
    {
        return $this->smsToPostKey;
    }

    public function setSmsToPostKey(?string $smsToPostKey): static
    {
        $this->smsToPostKey = $smsToPostKey;

        return $this;
    }

    public function getSmsToDefault(): ?string
    {
        return $this->smsToDefault;
    }

    public function setSmsToDefault(?string $smsToDefault): static
    {
        $this->smsToDefault = $smsToDefault;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getPipelineConfig(): ?array
    {
        return $this->pipelineConfig;
    }

    /**
     * @param array<string, mixed>|null $pipelineConfig
     */
    public function setPipelineConfig(?array $pipelineConfig): static
    {
        $this->pipelineConfig = $pipelineConfig;

        return $this;
    }
}
