<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Option;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Option>
 */
class OptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Option::class);
    }

    public function findOneByCategoryOptionNameNullDomain(string $category, string $optionName): ?Option
    {
        return $this->createQueryBuilder('o')
            ->where('o.category = :cat')
            ->andWhere('o.optionName = :name')
            ->andWhere('o.domain IS NULL')
            ->setParameter('cat', $category)
            ->setParameter('name', $optionName)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return array{items: list<Option>, total: int}
     */
    public function findFilteredPaginatedForAdmin(
        ?string $categoryExact,
        ?string $domainExact,
        ?string $optionNameContains,
        int $offset,
        int $limit,
    ): array {
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $qb = $this->createQueryBuilder('o');

        if (null !== $categoryExact && '' !== $categoryExact) {
            $qb->andWhere('o.category = :cat')->setParameter('cat', $categoryExact);
        }
        if (null !== $domainExact && '' !== $domainExact) {
            $qb->andWhere('o.domain = :dom')->setParameter('dom', $domainExact);
        }
        if (null !== $optionNameContains && '' !== trim($optionNameContains)) {
            $qb->andWhere('LOWER(o.optionName) LIKE :name')->setParameter('name', '%'.mb_strtolower(trim($optionNameContains)).'%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(o.id)')->getQuery()->getSingleScalarResult();

        $items = $qb
            ->select('o')
            ->orderBy('o.category', 'ASC')
            ->addOrderBy('o.domain', 'ASC')
            ->addOrderBy('o.optionName', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        /** @var list<Option> $items */
        return ['items' => $items, 'total' => $total];
    }
}
