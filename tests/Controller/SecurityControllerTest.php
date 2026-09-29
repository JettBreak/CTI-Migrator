<?php

namespace App\Tests\Controller;

use App\Enum\UserRole;
use App\Tests\AppTestCase;

final class SecurityControllerTest extends AppTestCase
{
    /** @return iterable<string, array{string}> */
    public static function protectedUrls(): iterable
    {
        foreach (['/', '/cards', '/accounts', '/migration', '/migration/batches/1', '/exports', '/exports/1/download'] as $url) {
            yield $url => [$url];
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('protectedUrls')]
    public function testAnonymousUsersAreSentToLogin(string $url): void
    {
        $client = $this->client;
        $client->request('GET', $url);
        self::assertResponseRedirects('/login');
    }

    public function testSignInPageShowsTheConsoleClockInTheAppTimezone(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.console-kicker', 'CORE ACCOUNT MIGRATION');
        self::assertSelectorExists('.console-clock[data-controller="clock"][data-clock-time-zone-value="Asia/Manila"]');
        self::assertSelectorTextContains('.console-clock', 'MANILA (UTC+8)');
        // The globe's marker is where the timezone is (Manila), from the timezone database.
        self::assertSelectorExists('canvas[data-login-globe-hud-value="true"][data-login-globe-marker-value="[120.97,14.59]"][data-login-globe-marker-label-value="MANILA"]');
    }

    public function testOfficerCanSignInAndOut(): void
    {
        $this->createUser('officer');
        $client = $this->client;
        $client->request('GET', '/login');
        self::assertResponseIsSuccessful();

        $client->submitForm('Sign in', ['_username' => 'officer', '_password' => self::PASSWORD]);
        self::assertResponseRedirects('/account/start');
        $client->followRedirect();
        self::assertResponseRedirects('/');
        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Migration overview');

        $client->clickLink('Sign out');
        self::assertResponseRedirects('/login');
        $client->request('GET', '/cards');
        self::assertResponseRedirects('/login');
    }

    public function testAdministratorCanSignOut(): void
    {
        $this->createUser('admin', UserRole::Admin);
        $this->loginAs('admin');
        $client = $this->client;
        $client->request('GET', '/');
        self::assertResponseRedirects('/admin/users');
        $client->followRedirect();
        self::assertResponseIsSuccessful();

        $client->clickLink('Sign out');
        self::assertResponseRedirects('/login');
        $client->request('GET', '/admin/users');
        self::assertResponseRedirects('/login');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $this->createUser('officer');
        $client = $this->client;
        $client->request('GET', '/login');
        $client->submitForm('Sign in', ['_username' => 'officer', '_password' => 'wrong']);
        self::assertResponseRedirects('/login');
        $client->followRedirect();
        self::assertSelectorExists('form p');
    }
}
