<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserAccountAuditLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Traçabilité des actions sur les comptes utilisateurs (invitation, blocage, suppression, etc.).
 */
#[ORM\Entity(repositoryClass: UserAccountAuditLogRepository::class)]
#[ORM\Table(name: 'user_account_audit_log')]
#[ORM\Index(name: 'IDX_user_audit_org_occurred', columns: ['organization_id', 'occurred_at'])]
class UserAccountAuditLog
{
    public const ACTION_USER_INVITED = 'user.invited';
    public const ACTION_INVITE_COMPLETED = 'user.invite_completed';
    public const ACTION_USER_BLOCKED = 'user.blocked';
    public const ACTION_USER_UNBLOCKED = 'user.unblocked';
    public const ACTION_USER_DELETED = 'user.deleted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(length: 64)]
    private string $action = '';

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actorUser = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $targetUser = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $targetEmail = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $details = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $clientIp = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeImmutable $occurredAt): static
    {
        $this->occurredAt = $occurredAt;

        return $this;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getActorUser(): ?User
    {
        return $this->actorUser;
    }

    public function setActorUser(?User $actorUser): static
    {
        $this->actorUser = $actorUser;

        return $this;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function setTargetUser(?User $targetUser): static
    {
        $this->targetUser = $targetUser;

        return $this;
    }

    public function getTargetEmail(): ?string
    {
        return $this->targetEmail;
    }

    public function setTargetEmail(?string $targetEmail): static
    {
        $this->targetEmail = $targetEmail;

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

    /**
     * @return array<string, mixed>|null
     */
    public function getDetails(): ?array
    {
        return $this->details;
    }

    /**
     * @param array<string, mixed>|null $details
     */
    public function setDetails(?array $details): static
    {
        $this->details = $details;

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
}
