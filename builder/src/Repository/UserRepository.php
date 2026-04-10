<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword);
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }

    public function save(User $user, bool $flush = true): void
    {
        $this->getEntityManager()->persist($user);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneByEmailVerificationToken(string $token): ?User
    {
        return $this->findOneBy(['emailVerificationToken' => $token]);
    }

    public function findOneByPasswordResetToken(string $token): ?User
    {
        return $this->findOneBy(['passwordResetToken' => $token]);
    }

    public function findOneByInviteToken(string $token): ?User
    {
        return $this->findOneBy(['inviteToken' => $token]);
    }

    /**
     * @return list<User>
     */
    public function findByOrganizationOrderedByEmail(Organization $organization): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin('u.organizationMemberships', 'm')
            ->andWhere('m.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function findUsersWithMembershipInOrganization(Organization $organization): array
    {
        return $this->createQueryBuilder('u')
            ->innerJoin('u.organizationMemberships', 'm')
            ->andWhere('m.organization = :o')
            ->setParameter('o', $organization)
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<User>
     */
    public function findAllOrderedByEmail(): array
    {
        return $this->createQueryBuilder('u')
            ->orderBy('u.email', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Liste paginée avec filtres — pour gestionnaires / administrateurs.
     *
     * @return array{items: list<User>, total: int}
     */
    public function findForListingPaginated(
        ?Organization $organizationScope,
        string $search,
        string $roleFilter,
        int $page,
        int $perPage,
    ): array {
        $qb = $this->createQueryBuilder('u');
        $distinct = false;

        if ($organizationScope instanceof Organization) {
            $qb->innerJoin('u.organizationMemberships', 'm')
                ->andWhere('m.organization = :orgScope')
                ->setParameter('orgScope', $organizationScope);
            $distinct = true;
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('LOWER(u.email)', ':searchLike'),
                    $qb->expr()->like('LOWER(COALESCE(u.displayName, \'\'))', ':searchLike'),
                ),
            );
            $qb->setParameter('searchLike', $like);
        }

        $this->applyRoleFilterToQueryBuilder($qb, $roleFilter);

        $qb->orderBy('u.email', 'ASC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $paginator = new Paginator($qb, $distinct);

        return [
            'items' => iterator_to_array($paginator->getIterator()),
            'total' => $paginator->count(),
        ];
    }

    private function applyRoleFilterToQueryBuilder(QueryBuilder $qb, string $roleFilter): void
    {
        $roleFilter = mb_strtolower(trim($roleFilter));
        if ($roleFilter === '') {
            return;
        }

        if ($roleFilter === 'admin') {
            $qb->andWhere('u.roles LIKE :rfAdmin')
                ->setParameter('rfAdmin', '%ROLE_ADMIN%');

            return;
        }

        if ($roleFilter === 'manager') {
            $qb->andWhere('u.roles LIKE :rfMgr')
                ->setParameter('rfMgr', '%ROLE_MANAGER%');

            return;
        }

        if ($roleFilter === 'member') {
            $qb->andWhere('u.roles NOT LIKE :rfAdminEx')
                ->andWhere('u.roles NOT LIKE :rfMgrEx')
                ->setParameter('rfAdminEx', '%ROLE_ADMIN%')
                ->setParameter('rfMgrEx', '%ROLE_MANAGER%');
        }
    }
}
