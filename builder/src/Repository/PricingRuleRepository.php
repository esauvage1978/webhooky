<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PricingRule;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PricingRule>
 */
class PricingRuleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PricingRule::class);
    }

    /**
     * @return list<PricingRule>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->orderBy('p.channel', 'ASC')
            ->addOrderBy('p.provider', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findActiveFor(string $channel, string $provider, \DateTimeImmutable $at): ?PricingRule
    {
        $rules = $this->createQueryBuilder('p')
            ->andWhere('p.active = true')
            ->andWhere('p.channel = :ch')
            ->andWhere('p.provider = :pr OR p.provider = :any')
            ->setParameter('ch', $channel)
            ->setParameter('pr', $provider)
            ->setParameter('any', '*')
            ->orderBy('p.provider', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($rules as $rule) {
            /** @var PricingRule $rule */
            $from = $rule->getValidFrom();
            $to = $rule->getValidTo();
            if ($from !== null && $at < $from) {
                continue;
            }
            if ($to !== null && $at > $to) {
                continue;
            }

            return $rule;
        }

        return null;
    }
}
