<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MonitoringSetting;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<MonitoringSetting>
 */
class MonitoringSettingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MonitoringSetting::class);
    }

    public function findByKey(string $key): ?MonitoringSetting
    {
        return $this->findOneBy(['settingKey' => $key]);
    }

    /**
     * @param array<string, mixed> $value
     */
    public function upsert(string $key, array $value): MonitoringSetting
    {
        $row = $this->findByKey($key);
        if ($row === null) {
            $row = new MonitoringSetting();
            $row->setSettingKey($key);
            $this->getEntityManager()->persist($row);
        }
        $row->setSettingValue($value);
        $this->getEntityManager()->flush();

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public function getValue(string $key, array $default = []): array
    {
        $row = $this->findByKey($key);

        return $row?->getSettingValue() ?? $default;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function allAsMap(): array
    {
        $out = [];
        foreach ($this->findAll() as $row) {
            $out[$row->getSettingKey()] = $row->getSettingValue();
        }

        return $out;
    }
}
