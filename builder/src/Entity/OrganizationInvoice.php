<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrganizationInvoiceRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Facture ou avoir rattaché à une organisation (PDF externe, ex. URL hébergée Stripe). */
#[ORM\Entity(repositoryClass: OrganizationInvoiceRepository::class)]
#[ORM\Table(name: 'organization_invoice')]
#[ORM\Index(name: 'IDX_organization_invoice_org_issued', columns: ['organization_id', 'issued_at'])]
class OrganizationInvoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Organization $organization = null;

    #[ORM\Column(length: 64)]
    private string $reference = '';

    #[ORM\Column(length: 255)]
    private string $title = '';

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2)]
    private string $amountEur = '0.00';

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $issuedAt = null;

    #[ORM\Column(length: 2048, nullable: true)]
    private ?string $pdfUrl = null;

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

    public function getReference(): string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getAmountEur(): string
    {
        return $this->amountEur;
    }

    public function setAmountEur(string|float $amountEur): static
    {
        $this->amountEur = is_string($amountEur) ? $amountEur : number_format((float) $amountEur, 2, '.', '');

        return $this;
    }

    public function getIssuedAt(): ?\DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function setIssuedAt(\DateTimeImmutable $issuedAt): static
    {
        $this->issuedAt = $issuedAt;

        return $this;
    }

    public function getPdfUrl(): ?string
    {
        return $this->pdfUrl;
    }

    public function setPdfUrl(?string $pdfUrl): static
    {
        $this->pdfUrl = $pdfUrl !== null && $pdfUrl !== '' ? $pdfUrl : null;

        return $this;
    }
}
