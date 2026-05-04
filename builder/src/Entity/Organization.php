<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationRepository;
use App\Subscription\BillingStatus;
use App\Subscription\SubscriptionPlan;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'UNIQ_ORGANIZATION_NAME', fields: ['name'])]
#[ORM\UniqueConstraint(name: 'UNIQ_ORGANIZATION_WEBHOOK_PUBLIC_PREFIX', fields: ['webhookPublicPrefix'])]
#[ORM\HasLifecycleCallbacks]
class Organization
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180)]
    private string $name = '';

    /**
     * Préfixe hexadécimal (12 car.) unique : l’URL d’ingress utilise préfixe + jeton public du workflow (UUID).
     */
    #[ORM\Column(length: 12)]
    private string $webhookPublicPrefix = '';

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billingLine1 = null;

    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $billingLine2 = null;

    #[Assert\Length(max: 32)]
    #[ORM\Column(length: 32, nullable: true)]
    private ?string $billingPostalCode = null;

    #[Assert\Length(max: 128)]
    #[ORM\Column(length: 128, nullable: true)]
    private ?string $billingCity = null;

    /** Code pays ISO 3166-1 alpha-2 (ex. FR) */
    #[Assert\Length(max: 2)]
    #[ORM\Column(length: 2, nullable: true)]
    private ?string $billingCountry = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Stockage DB en chaîne (évite l’échec d’hydratation enum si la base contient '' ou d’anciennes valeurs).
     * Utiliser getSubscriptionPlan() / setSubscriptionPlan() pour le typage métier.
     */
    #[ORM\Column(length: 32)]
    private string $subscriptionPlan = 'free';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(length: 32)]
    private string $billingStatus = 'active';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $subscriptionCurrentPeriodEnd = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    /** Événements ingress comptabilisés (requêtes webhook acceptées). */
    #[ORM\Column(options: ['default' => 0])]
    private int $eventsConsumed = 0;

    /** Volume d’événements acheté en packs (s’ajoute au quota inclus du forfait). */
    #[ORM\Column(options: ['default' => 0])]
    private int $eventsExtraQuota = 0;

    /**
     * Structure interne : pas de blocage ingress ni de plafonds sur cette organisation (forfaits Stripe / simulation ignorés).
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $subscriptionExempt = false;

    /** @var Collection<int, OrganizationMembership> */
    #[ORM\OneToMany(targetEntity: OrganizationMembership::class, mappedBy: 'organization', orphanRemoval: true, cascade: ['persist'])]
    private Collection $memberships;

    /**
     * Configuration IA par organisation (provider, modèle, URL de base…).
     *
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $aiSettings = null;

    public function __construct()
    {
        $this->memberships = new ArrayCollection();
    }

    #[ORM\PostLoad]
    public function normalizePlanFieldsAfterLoad(): void
    {
        $this->normalizePlanStorage();
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function onPrePersistOrganization(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
        $this->normalizePlanStorage();
    }

    private function normalizePlanStorage(): void
    {
        $raw = trim($this->subscriptionPlan);
        $pt = SubscriptionPlan::tryFrom($raw);
        if ($raw === '' || $raw === 'trial') {
            $this->subscriptionPlan = SubscriptionPlan::Free->value;
        } elseif ($raw === 'single_webhook') {
            $this->subscriptionPlan = SubscriptionPlan::Starter->value;
        } elseif ($raw === 'unlimited') {
            $this->subscriptionPlan = SubscriptionPlan::Pro->value;
        } elseif ($pt !== null) {
            $this->subscriptionPlan = $pt->value;
        } else {
            $this->subscriptionPlan = SubscriptionPlan::Pro->value;
        }

        $b = trim($this->billingStatus);
        $bst = BillingStatus::tryFrom($b);
        if ($b === '' || $bst === null) {
            $this->billingStatus = BillingStatus::Active->value;
        } else {
            $this->billingStatus = $bst->value;
        }
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getWebhookPublicPrefix(): string
    {
        return $this->webhookPublicPrefix;
    }

    public function setWebhookPublicPrefix(string $webhookPublicPrefix): static
    {
        $this->webhookPublicPrefix = strtolower($webhookPublicPrefix);

        return $this;
    }

    public function getBillingLine1(): ?string
    {
        return $this->billingLine1;
    }

    public function setBillingLine1(?string $billingLine1): static
    {
        $this->billingLine1 = $billingLine1 !== null && $billingLine1 !== '' ? $billingLine1 : null;

        return $this;
    }

    public function getBillingLine2(): ?string
    {
        return $this->billingLine2;
    }

    public function setBillingLine2(?string $billingLine2): static
    {
        $this->billingLine2 = $billingLine2 !== null && $billingLine2 !== '' ? $billingLine2 : null;

        return $this;
    }

    public function getBillingPostalCode(): ?string
    {
        return $this->billingPostalCode;
    }

    public function setBillingPostalCode(?string $billingPostalCode): static
    {
        $this->billingPostalCode = $billingPostalCode !== null && $billingPostalCode !== '' ? $billingPostalCode : null;

        return $this;
    }

    public function getBillingCity(): ?string
    {
        return $this->billingCity;
    }

    public function setBillingCity(?string $billingCity): static
    {
        $this->billingCity = $billingCity !== null && $billingCity !== '' ? $billingCity : null;

        return $this;
    }

    public function getBillingCountry(): ?string
    {
        return $this->billingCountry;
    }

    public function setBillingCountry(?string $billingCountry): static
    {
        if ($billingCountry === null || $billingCountry === '') {
            $this->billingCountry = null;

            return $this;
        }
        $this->billingCountry = strtoupper(substr($billingCountry, 0, 2));

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getSubscriptionPlan(): SubscriptionPlan
    {
        return SubscriptionPlan::tryFrom($this->subscriptionPlan) ?? SubscriptionPlan::Free;
    }

    public function setSubscriptionPlan(SubscriptionPlan $subscriptionPlan): static
    {
        $this->subscriptionPlan = $subscriptionPlan->value;

        return $this;
    }

    public function getTrialEndsAt(): ?\DateTimeImmutable
    {
        return $this->trialEndsAt;
    }

    public function setTrialEndsAt(?\DateTimeImmutable $trialEndsAt): static
    {
        $this->trialEndsAt = $trialEndsAt;

        return $this;
    }

    public function getBillingStatus(): BillingStatus
    {
        return BillingStatus::tryFrom($this->billingStatus) ?? BillingStatus::Active;
    }

    public function setBillingStatus(BillingStatus $billingStatus): static
    {
        $this->billingStatus = $billingStatus->value;

        return $this;
    }

    public function getSubscriptionCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->subscriptionCurrentPeriodEnd;
    }

    public function setSubscriptionCurrentPeriodEnd(?\DateTimeImmutable $subscriptionCurrentPeriodEnd): static
    {
        $this->subscriptionCurrentPeriodEnd = $subscriptionCurrentPeriodEnd;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(?string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getEventsConsumed(): int
    {
        return $this->eventsConsumed;
    }

    public function setEventsConsumed(int $eventsConsumed): static
    {
        $this->eventsConsumed = $eventsConsumed;

        return $this;
    }

    public function getEventsExtraQuota(): int
    {
        return $this->eventsExtraQuota;
    }

    public function setEventsExtraQuota(int $eventsExtraQuota): static
    {
        $this->eventsExtraQuota = max(0, $eventsExtraQuota);

        return $this;
    }

    public function isSubscriptionExempt(): bool
    {
        return $this->subscriptionExempt;
    }

    public function setSubscriptionExempt(bool $subscriptionExempt): static
    {
        $this->subscriptionExempt = $subscriptionExempt;

        return $this;
    }

    public function applyFreePlan(): void
    {
        $this->subscriptionPlan = SubscriptionPlan::Free->value;
        $this->trialEndsAt = null;
        $this->billingStatus = BillingStatus::Active->value;
        $this->subscriptionCurrentPeriodEnd = null;
        $this->eventsExtraQuota = 0;
    }

    /** Passage à un forfait payant (hors synchro Stripe). */
    public function applyPaidPlan(SubscriptionPlan $plan, ?\DateTimeImmutable $periodEnd = null): void
    {
        if ($plan === SubscriptionPlan::Free) {
            $this->applyFreePlan();

            return;
        }
        $this->subscriptionPlan = $plan->value;
        $this->trialEndsAt = null;
        $this->billingStatus = BillingStatus::Active->value;
        $this->subscriptionCurrentPeriodEnd = $periodEnd;
    }

    /** Admin : repasse en Free et optionnellement vide les compteurs d’usage. */
    public function applyAdminResetToFree(bool $clearEventCounters = false): void
    {
        $this->applyFreePlan();
        $this->stripeSubscriptionId = null;
        if ($clearEventCounters) {
            $this->eventsConsumed = 0;
            $this->eventsExtraQuota = 0;
        }
    }

    /**
     * @return Collection<int, OrganizationMembership>
     */
    public function getMemberships(): Collection
    {
        return $this->memberships;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getAiSettings(): ?array
    {
        return $this->aiSettings;
    }

    /**
     * @param array<string, mixed>|null $aiSettings
     */
    public function setAiSettings(?array $aiSettings): static
    {
        $this->aiSettings = $aiSettings;

        return $this;
    }
}
