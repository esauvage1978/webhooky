<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WebhookProjectRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Regroupe des workflows webhook par projet. Un workflow appartient à exactement un projet.
 * Chaque organisation possède un projet « Général » (isDefault) pour le classement par défaut.
 */
#[ORM\Entity(repositoryClass: WebhookProjectRepository::class)]
#[ORM\Table(name: 'webhook_project')]
#[ORM\UniqueConstraint(name: 'uniq_webhook_project_org_name', columns: ['organization_id', 'name'])]
#[ORM\HasLifecycleCallbacks]
class WebhookProject
{
    /** Nom affiché du projet par défaut (unique par organisation). */
    public const DEFAULT_NAME = 'Général';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180)]
    private string $name = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** Indique le projet « Général » par organisation (un seul par org). */
    #[ORM\Column(options: ['default' => false])]
    private bool $isDefault = false;

    /**
     * @var Collection<int, FormWebhook>
     */
    #[ORM\OneToMany(targetEntity: FormWebhook::class, mappedBy: 'project')]
    private Collection $webhooks;

    public function __construct()
    {
        $this->webhooks = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

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

    public function isDefault(): bool
    {
        return $this->isDefault;
    }

    public function setIsDefault(bool $isDefault): static
    {
        $this->isDefault = $isDefault;

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

    /**
     * @return Collection<int, FormWebhook>
     */
    public function getWebhooks(): Collection
    {
        return $this->webhooks;
    }
}
