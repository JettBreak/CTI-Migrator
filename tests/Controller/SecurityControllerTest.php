<?php

namespace App\Tests\Controller;

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
