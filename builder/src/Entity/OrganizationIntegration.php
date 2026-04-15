<?php

declare(strict_types=1);

namespace App\Entity;

use App\Integration\OrganizationIntegrationType;
use App\Repository\OrganizationIntegrationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrganizationIntegrationRepository::class)]
#[ORM\Table(name: 'organization_integration')]
#[ORM\HasLifecycleCallbacks]
class OrganizationIntegration
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    #[ORM\Column(length: 32)]
    private string $type = OrganizationIntegrationType::GSC;

    #[ORM\Column(type: Types::TEXT)]
    private string $accessTokenCipher = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $refreshTokenCipher = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $scope = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $siteUrl = null;

    /**
     * @var array<string, mixed>|null
     */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $extra = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getAccessTokenCipher(): string
    {
        return $this->accessTokenCipher;
    }

    public function setAccessTokenCipher(string $accessTokenCipher): static
    {
        $this->accessTokenCipher = $accessTokenCipher;

        return $this;
    }

    public function getRefreshTokenCipher(): string
    {
        return $this->refreshTokenCipher;
    }

    public function setRefreshTokenCipher(string $refreshTokenCipher): static
    {
        $this->refreshTokenCipher = $refreshTokenCipher;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getScope(): ?string
    {
        return $this->scope;
    }

    public function setScope(?string $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getSiteUrl(): ?string
    {
        return $this->siteUrl;
    }

    public function setSiteUrl(?string $siteUrl): static
    {
        $this->siteUrl = $siteUrl !== null && $siteUrl !== '' ? $siteUrl : null;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getExtra(): ?array
    {
        return $this->extra;
    }

    /**
     * @param array<string, mixed>|null $extra
     */
    public function setExtra(?array $extra): static
    {
        $this->extra = $extra;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt ??= new \DateTimeImmutable();
    }
}
