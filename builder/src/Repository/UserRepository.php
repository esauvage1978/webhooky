<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Organization;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
}
