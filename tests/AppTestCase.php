<?php

namespace App\Tests;

use App\Entity\User;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Functional test base: a fresh SQLite app database per test, a mock clock (move it with
 * $this->clock->sleep()), and helpers to create accounts and sign in.
 * Core is replaced by App\Core\InMemoryCoreLinkGateway in the test environment.
 */
abstract class AppTestCase extends WebTestCase
{
    /** Password of the accounts made by createUser(); it meets the password policy. */
    protected const PASSWORD = 'Test-password-1';

    protected KernelBrowser $client;
    protected MockClock $clock;

    protected function setUp(): void
    {
        $this->clock = new MockClock();
        Clock::set($this->clock);

        $this->client = static::createClient();
        // Keep one kernel (and so one in-memory core) across the requests of a test.
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');
        // Sign-in throttling counts attempts in a cache that outlives a test.
        static::getContainer()->get('cache.rate_limiter')->clear();

        $em = $this->em();
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Clock::set(new \Symfony\Component\Clock\NativeClock());
    }

    /**
     * The account (created if missing). Its role follows the name unless given: "approver…" and
     * "admin…" names get those roles, anything else is a migration officer.
     */
    protected function createUser(string $username, ?UserRole $role = null, string $password = self::PASSWORD, bool $temporary = false): User
    {
        $users = static::getContainer()->get(UserRepository::class);
        if ($user = $users->findOneByUsername($username)) {
            return $user;
        }

        $role ??= match (true) {
            str_starts_with($username, 'approver') => UserRole::Approver,
            str_starts_with($username, 'admin') => UserRole::Admin,
            default => UserRole::Officer,
        };
        $now = $this->clock->now();
        $user = new User($username, ucfirst($username).' Tester', $role, 'test', $now);
        $user->setPassword(static::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $password), $now, $temporary);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    /** Signs in as the account (created if missing), as a completed sign-in would. */
    protected function loginAs(string $username): User
    {
        $user = $this->createUser($username);
        $user->recordLogin('127.0.0.1', bin2hex(random_bytes(16)), $this->clock->now());
        $this->em()->flush();
        $this->client->loginUser($user);

        return $user;
    }

    /** The account as stored now (the entity manager is reset after every request). */
    protected function user(string $username): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneByUsername($username);
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
