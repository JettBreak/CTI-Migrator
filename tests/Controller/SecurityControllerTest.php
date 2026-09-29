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

    public function testSigningInAndOutEndTheNextPagesLoaderWithTheRocketSwipe(): void
    {
        // Signing in asks the app's pages for the swipe; a failed sign-in, back here, fades as usual.
        $this->client->request('GET', '/login');
        self::assertSelectorExists('html[data-page-loader-arrival-value="sign-in"]');
        self::assertSelectorExists('form[action="/login"][data-controller~="rocket-swipe"][data-action~="rocket-swipe#depart"][data-rocket-swipe-to-param="app"]');
        self::assertSelectorExists('template#rocket-swipe .rocket-swipe-front .rocket-swipe-craft svg.rocket');

        // Signing out shows the loader at once and asks the sign-in page for the swipe; the link itself still signs out.
        $this->loginAs('officer');
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-page-loader-arrival-value="app"]');
        self::assertSelectorExists('header a[href^="/logout?_csrf_token="][data-controller="rocket-swipe"][data-action="rocket-swipe#depart"][data-rocket-swipe-to-param="sign-in"][data-rocket-swipe-loader-param="true"]');
        // The rocket, its trail and its sparks, cloned to wipe the loading overlay away.
        self::assertSelectorExists('template#rocket-swipe .rocket-swipe-front .rocket-swipe-craft svg.rocket');
        self::assertSelectorExists('template#rocket-swipe .rocket-swipe-sparks');
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
