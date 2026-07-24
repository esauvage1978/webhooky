<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\MonitoringSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MonitoringSettingRepository::class)]
#[ORM\Table(name: 'monitoring_setting')]
#[ORM\UniqueConstraint(name: 'UNIQ_mon_setting_key', columns: ['setting_key'])]
class MonitoringSetting
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 96)]
    private string $settingKey = '';

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $settingValue = [];

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSettingKey(): string
    {
        return $this->settingKey;
    }

    public function setSettingKey(string $settingKey): static
    {
        $this->settingKey = $settingKey;

        return $this;
    }

    /** @return array<string, mixed> */
    public function getSettingValue(): array
    {
        return $this->settingValue;
    }

    /** @param array<string, mixed> $settingValue */
    public function setSettingValue(array $settingValue): static
    {
        $this->settingValue = $settingValue;
        $this->updatedAt = new \DateTimeImmutable();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
