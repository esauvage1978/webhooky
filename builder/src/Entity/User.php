<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[ORM\UniqueConstraint(name: 'UNIQ_USER_EMAIL', fields: ['email'])]
#[ORM\HasLifecycleCallbacks]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180)]
    private string $email = '';

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    private array $roles = [];

    #[ORM\Column(length: 255)]
    private string $password = '';

    #[ORM\Column(options: ['default' => false])]
    private bool $emailVerified = false;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $emailVerificationToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationExpiresAt = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetExpiresAt = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $accountEnabled = true;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $inviteToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $inviteExpiresAt = null;

    /** Organisation courante (contexte de travail après connexion). */
    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Organization $organization = null;

    /** @var Collection<int, OrganizationMembership> */
    #[ORM\OneToMany(targetEntity: OrganizationMembership::class, mappedBy: 'user', orphanRemoval: true, cascade: ['persist'])]
    private Collection $organizationMemberships;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $displayName = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $avatarKey = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $profileCompletedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $planOnboardingCompleted = false;

    public function __construct()
    {
        $this->organizationMemberships = new ArrayCollection();
    }

    #[ORM\PrePersist]
    public function ensureCreatedAt(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = 'ROLE_USER';

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    public function eraseCredentials(): void
    {
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
     * @return Collection<int, OrganizationMembership>
     */
    public function getOrganizationMemberships(): Collection
    {
        return $this->organizationMemberships;
    }

    public function hasMembershipInOrganization(?Organization $organization): bool
    {
        if (!$organization instanceof Organization || $organization->getId() === null) {
            return false;
        }

        foreach ($this->organizationMemberships as $m) {
            if ($m->getOrganization()?->getId() === $organization->getId()) {
                return true;
            }
        }

        return false;
    }

    public function addOrganizationMembership(Organization $organization): void
    {
        if ($this->hasMembershipInOrganization($organization)) {
            return;
        }

        $row = new OrganizationMembership();
        $row->setUser($this);
        $row->setOrganization($organization);
        $this->organizationMemberships->add($row);
    }

    public function removeMembershipForOrganization(Organization $organization): void
    {
        foreach ($this->organizationMemberships as $m) {
            if ($m->getOrganization()?->getId() === $organization->getId()) {
                $this->organizationMemberships->removeElement($m);

                return;
            }
        }
    }

    public function hasAnyOrganizationMembership(): bool
    {
        return !$this->organizationMemberships->isEmpty();
    }

    /**
     * @return list<Organization>
     */
    public function getMemberOrganizations(): array
    {
        $list = [];
        foreach ($this->organizationMemberships as $m) {
            $o = $m->getOrganization();
            if ($o instanceof Organization) {
                $list[] = $o;
            }
        }

        usort($list, static fn (Organization $a, Organization $b) => strcasecmp($a->getName(), $b->getName()));

        return $list;
    }

    public function isEmailVerified(): bool
    {
        return $this->emailVerified;
    }

    public function setEmailVerified(bool $emailVerified): static
    {
        $this->emailVerified = $emailVerified;

        return $this;
    }

    public function getEmailVerificationToken(): ?string
    {
        return $this->emailVerificationToken;
    }

    public function setEmailVerificationToken(?string $emailVerificationToken): static
    {
        $this->emailVerificationToken = $emailVerificationToken;

        return $this;
    }

    public function getEmailVerificationExpiresAt(): ?\DateTimeImmutable
    {
        return $this->emailVerificationExpiresAt;
    }

    public function setEmailVerificationExpiresAt(?\DateTimeImmutable $emailVerificationExpiresAt): static
    {
        $this->emailVerificationExpiresAt = $emailVerificationExpiresAt;

        return $this;
    }

    public function getPasswordResetToken(): ?string
    {
        return $this->passwordResetToken;
    }

    public function setPasswordResetToken(?string $passwordResetToken): static
    {
        $this->passwordResetToken = $passwordResetToken;

        return $this;
    }

    public function getPasswordResetExpiresAt(): ?\DateTimeImmutable
    {
        return $this->passwordResetExpiresAt;
    }

    public function setPasswordResetExpiresAt(?\DateTimeImmutable $passwordResetExpiresAt): static
    {
        $this->passwordResetExpiresAt = $passwordResetExpiresAt;

        return $this;
    }

    public function isAccountEnabled(): bool
    {
        return $this->accountEnabled;
    }

    public function setAccountEnabled(bool $accountEnabled): static
    {
        $this->accountEnabled = $accountEnabled;

        return $this;
    }

    public function getInviteToken(): ?string
    {
        return $this->inviteToken;
    }

    public function setInviteToken(?string $inviteToken): static
    {
        $this->inviteToken = $inviteToken;

        return $this;
    }

    public function getInviteExpiresAt(): ?\DateTimeImmutable
    {
        return $this->inviteExpiresAt;
    }

    public function setInviteExpiresAt(?\DateTimeImmutable $inviteExpiresAt): static
    {
        $this->inviteExpiresAt = $inviteExpiresAt;

        return $this;
    }

    public function hasPendingInvite(): bool
    {
        return $this->inviteToken !== null && $this->inviteToken !== '';
    }

    public function isAppAdmin(): bool
    {
        return \in_array('ROLE_ADMIN', $this->roles, true);
    }

    public function isAppManager(): bool
    {
        return \in_array('ROLE_MANAGER', $this->roles, true);
    }

    /** Membre standard (pas administrateur ni gestionnaire). */
    public function isOrgMember(): bool
    {
        return !$this->isAppAdmin() && !$this->isAppManager();
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    public function getDisplayName(): ?string
    {
        return $this->displayName;
    }

    public function setDisplayName(?string $displayName): static
    {
        $this->displayName = $displayName !== null && $displayName !== '' ? $displayName : null;

        return $this;
    }

    public function getAvatarKey(): ?string
    {
        return $this->avatarKey;
    }

    public function setAvatarKey(?string $avatarKey): static
    {
        $this->avatarKey = $avatarKey !== null && $avatarKey !== '' ? $avatarKey : null;

        return $this;
    }

    public function getProfileCompletedAt(): ?\DateTimeImmutable
    {
        return $this->profileCompletedAt;
    }

    public function setProfileCompletedAt(?\DateTimeImmutable $profileCompletedAt): static
    {
        $this->profileCompletedAt = $profileCompletedAt;

        return $this;
    }

    public function isProfileOnboardingComplete(): bool
    {
        return $this->profileCompletedAt !== null;
    }

    public function isPlanOnboardingComplete(): bool
    {
        return $this->planOnboardingCompleted;
    }

    public function setPlanOnboardingCompleted(bool $planOnboardingCompleted): static
    {
        $this->planOnboardingCompleted = $planOnboardingCompleted;

        return $this;
    }
}
