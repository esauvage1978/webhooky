<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\FormWebhookRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Déclencheur d’ingress : URL unique, organisation. Les actions (Mailjet, etc.) sont dans FormWebhookAction.
 */
#[ORM\Entity(repositoryClass: FormWebhookRepository::class)]
#[ORM\Table(name: 'form_webhook')]
#[ORM\HasLifecycleCallbacks]
class FormWebhook
{
    public const NOTIFICATION_EMAIL_CREATOR = 'creator';

    public const NOTIFICATION_EMAIL_CUSTOM = 'custom';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Token opaque dans l’URL (UUID v4). */
    #[ORM\Column(length: 36, unique: true)]
    private string $publicToken = '';

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\ManyToOne(targetEntity: WebhookProject::class, inversedBy: 'webhooks')]
    #[ORM\JoinColumn(name: 'project_id', nullable: false, onDelete: 'RESTRICT')]
    private ?WebhookProject $project = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /**
     * Métadonnées libres (évolution : webhooks sortants, tags, rate limit…).
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 16, options: ['default' => self::NOTIFICATION_EMAIL_CREATOR])]
    private string $notificationEmailSource = self::NOTIFICATION_EMAIL_CREATOR;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $notificationCustomEmail = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $notifyOnError = true;

    #[ORM\Column(options: ['default' => false])]
    private bool $notifyOnSuccess = false;

    #[ORM\Column(options: ['default' => true])]
    private bool $active = true;

    /** Incrémenté à chaque modification réelle du workflow (hors journaux d’exécution). */
    #[ORM\Column(options: ['default' => 1])]
    private int $version = 1;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * @var Collection<int, FormWebhookAction>
     */
    #[ORM\OneToMany(targetEntity: FormWebhookAction::class, mappedBy: 'formWebhook', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    private Collection $actions;

    public function __construct()
    {
        $this->actions = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        if ($this->publicToken === '') {
            $this->publicToken = Uuid::v4()->toRfc4122();
        }
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * @return list<FormWebhookAction>
     */
    public function getActiveActionsOrdered(): array
    {
        $out = [];
        foreach ($this->actions as $a) {
            if ($a->isActive()) {
                $out[] = $a;
            }
        }

        return $out;
    }

    /**
     * @return Collection<int, FormWebhookAction>
     */
    public function getActions(): Collection
    {
        return $this->actions;
    }

    public function addAction(FormWebhookAction $action): static
    {
        if (!$this->actions->contains($action)) {
            $this->actions->add($action);
            $action->setFormWebhook($this);
        }

        return $this;
    }

    public function removeAction(FormWebhookAction $action): static
    {
        $this->actions->removeElement($action);

        return $this;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPublicToken(): string
    {
        return $this->publicToken;
    }

    public function setPublicToken(string $publicToken): static
    {
        $this->publicToken = $publicToken;

        return $this;
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

    public function getProject(): ?WebhookProject
    {
        return $this->project;
    }

    public function setProject(?WebhookProject $project): static
    {
        $this->project = $project;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getNotificationEmailSource(): string
    {
        return $this->notificationEmailSource;
    }

    public function setNotificationEmailSource(string $notificationEmailSource): static
    {
        $this->notificationEmailSource = $notificationEmailSource;

        return $this;
    }

    public function getNotificationCustomEmail(): ?string
    {
        return $this->notificationCustomEmail;
    }

    public function setNotificationCustomEmail(?string $notificationCustomEmail): static
    {
        $this->notificationCustomEmail = $notificationCustomEmail;

        return $this;
    }

    public function isNotifyOnError(): bool
    {
        return $this->notifyOnError;
    }

    public function setNotifyOnError(bool $notifyOnError): static
    {
        $this->notifyOnError = $notifyOnError;

        return $this;
    }

    public function isNotifyOnSuccess(): bool
    {
        return $this->notifyOnSuccess;
    }

    public function setNotifyOnSuccess(bool $notifyOnSuccess): static
    {
        $this->notifyOnSuccess = $notifyOnSuccess;

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

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

    public function getVersion(): int
    {
        return $this->version;
    }

    public function setVersion(int $version): static
    {
        $this->version = max(1, $version);

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
