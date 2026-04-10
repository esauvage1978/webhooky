<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Paramètre clé/valeur configurable (admin uniquement), groupé par catégorie et domaine optionnel.
 * Table `app_option` : le nom SQL `option` est évité (mot réservé sur plusieurs SGBD).
 */
#[ORM\Entity(repositoryClass: OptionRepository::class)]
#[ORM\Table(name: 'app_option')]
class Option
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[Assert\NotBlank]
    #[ORM\Column(type: Types::TEXT)]
    private string $optionValue = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 191)]
    #[ORM\Column(length: 191)]
    private string $optionName = '';

    #[Assert\Length(max: 191)]
    #[ORM\Column(length: 191, nullable: true)]
    private ?string $domain = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 191)]
    #[ORM\Column(length: 191)]
    private string $category = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getOptionValue(): string
    {
        return $this->optionValue;
    }

    public function setOptionValue(string $optionValue): static
    {
        $this->optionValue = $optionValue;

        return $this;
    }

    public function getOptionName(): string
    {
        return $this->optionName;
    }

    public function setOptionName(string $optionName): static
    {
        $this->optionName = $optionName;

        return $this;
    }

    public function getDomain(): ?string
    {
        return $this->domain;
    }

    public function setDomain(?string $domain): static
    {
        $this->domain = $domain;

        return $this;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }
}
