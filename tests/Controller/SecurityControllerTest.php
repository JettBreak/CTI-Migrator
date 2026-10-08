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
        // The timezone database supplies the coordinates. Its precise Manila value can differ
        // slightly between PHP/tzdata releases, so assert the marker's city and map position
        // rather than coupling this functional test to a particular database revision.
        $marker = $this->client->getCrawler()
            ->filter('canvas[data-login-globe-hud-value="true"][data-login-globe-marker-label-value="MANILA"]')
            ->attr('data-login-globe-marker-value');
        self::assertIsString($marker);
        $coordinates = json_decode($marker, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($coordinates);
        self::assertCount(2, $coordinates);
        self::assertEqualsWithDelta(120.97, $coordinates[0], 0.02);
        self::assertEqualsWithDelta(14.59, $coordinates[1], 0.02);
    }

    public function testSigningInAndOutEndTheNextPagesLoaderWithTheRocketSwipe(): void
    {
        // Signing in asks the app's pages for the swipe; a failed sign-in, back here, fades as usual.
        $this->client->request('GET', '/login');
        self::assertSelectorExists('html[data-page-loader-arrival-value="sign-in"]');
        self::assertSelectorExists('form[action="/login"][data-controller~="rocket-swipe"][data-action~="rocket-swipe#depart"][data-rocket-swipe-to-param="app"]');
        self::assertSelectorExists('template#rocket-swipe .rocket-swipe-front .rocket-swipe-craft svg.rocket');
        // The credentials are checked before anything loads: no loading overlay on submit, only a busy button.
        self::assertSelectorNotExists('form[action="/login"][data-action~="page-loader#show"]');
        self::assertSelectorExists('form[action="/login"][data-controller~="submit-busy"][data-action~="submit-busy#start"][data-submit-busy-label-value="Signing in…"] button[type="submit"][data-submit-busy-target="button"]');

        // Signing out shows the loader at once and asks the sign-in page for the swipe; the link itself still signs out.
        $this->loginAs('officer');
        $this->client->request('GET', '/');
        self::assertSelectorExists('html[data-page-loader-arrival-value="app"]');
        // Arriving after signing in, the overlay is kept solid until the swipe, so the page does not show through.
        self::assertStringContainsString("classList.add('swipe-pending')", $this->client->getCrawler()->filter('head')->html());
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
