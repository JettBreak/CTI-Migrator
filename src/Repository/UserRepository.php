<?php

namespace App\Repository;

use App\Entity\User;
use App\Enum\UserRole;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;

/** @extends ServiceEntityRepository<User> */
class UserRepository extends ServiceEntityRepository implements PasswordUpgraderInterface
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findOneByUsername(string $username): ?User
    {
        return $this->findOneBy(['username' => $username]);
    }

    /** @return list<User> */
    public function findAllOrdered(): array
    {
        return $this->findBy([], ['active' => 'DESC', 'username' => 'ASC']);
    }

    public function countActiveWithRole(UserRole $role): int
    {
        return $this->count(['role' => $role, 'active' => true]);
    }

    /** @return list<User> active accounts with no sign-in (or creation/re-enabling) since $before */
    public function findDormant(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.active = true')
            ->andWhere('u.activeSince < :before')
            ->andWhere('u.lastLoginAt IS NULL OR u.lastLoginAt < :before')
            ->setParameter('before', $before)
            ->getQuery()->getResult();
    }

    /** Rehashes a password on sign-in when the hashing algorithm or cost has been raised. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException(sprintf('Instances of "%s" are not supported.', $user::class));
        }

        $user->setPassword($newHashedPassword, $user->getPasswordChangedAt(), $user->mustChangePassword());
        $this->getEntityManager()->flush();
    }
}
