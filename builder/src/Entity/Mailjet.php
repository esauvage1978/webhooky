<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MailjetRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: MailjetRepository::class)]
#[ORM\Table(name: 'mailjet')]
class Mailjet
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 180)]
    #[ORM\Column(length: 180)]
    private string $name = '';

    #[Assert\NotBlank(message: 'La clé API publique est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $apiKeyPublic = '';

    #[Assert\NotBlank(message: 'La clé API privée est obligatoire.')]
    #[Assert\Length(max: 255)]
    #[ORM\Column(length: 255)]
    private string $apiKeyPrivate = '';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ?User $createdBy = null;

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

    public function getApiKeyPublic(): string
    {
        return $this->apiKeyPublic;
    }

    public function setApiKeyPublic(string $apiKeyPublic): static
    {
        $this->apiKeyPublic = $apiKeyPublic;

        return $this;
    }

    public function getApiKeyPrivate(): string
    {
        return $this->apiKeyPrivate;
    }

    public function setApiKeyPrivate(string $apiKeyPrivate): static
    {
        $this->apiKeyPrivate = $apiKeyPrivate;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
