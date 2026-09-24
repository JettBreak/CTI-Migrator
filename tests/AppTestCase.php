<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Functional test base: a fresh SQLite app database per test, and helpers to sign in.
 * Core is replaced by App\Core\InMemoryCoreLinkGateway in the test environment.
 */
abstract class AppTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one kernel (and so one in-memory core) across the requests of a test.
        $this->client->disableReboot();
        $this->client->setServerParameter('HTTP_ORIGIN', 'http://localhost');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $tool->dropSchema($metadata);
        $tool->createSchema($metadata);
    }

    protected function loginAs(string $username): void
    {
        $users = static::getContainer()->get('security.user.provider.concrete.users_in_memory');
        $this->client->loginUser($users->loadUserByIdentifier($username));
    }
}
