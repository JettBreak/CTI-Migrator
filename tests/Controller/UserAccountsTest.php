<?php

namespace App\Tests\Controller;

use App\Entity\PasswordHistory;
use App\Entity\UserAuditEntry;
use App\Message\DisableDormantUsers;
use App\MessageHandler\DisableDormantUsersHandler;
use App\Security\UserAdministration;
use App\Tests\AppTestCase;

/** Sign-in safeguards, password rules and session limits, exercised through HTTP requests. */
final class UserAccountsTest extends AppTestCase
{
    public function testSigningInStartsTheOneSessionAndIsAudited(): void
    {
        $user = $this->createUser('officer');

        $this->signIn('officer', self::PASSWORD);
        self::assertResponseRedirects('/account/start');

        $user = $this->user('officer');
        self::assertNotNull($user->getLastLoginAt());
        self::assertSame('127.0.0.1', $user->getLastLoginIp());
        self::assertNotNull($user->getSessionToken());
        self::assertSame([UserAuditEntry::LOGIN], $this->auditActions('officer'));
    }

    public function testEveryRefusedSignInLooksTheSameAndFiveFailuresLockTheAccount(): void
    {
        $user = $this->createUser('officer');

        self::assertSame('Invalid username or password.', $this->failedSignInMessage('nobody', self::PASSWORD));
        for ($i = 1; $i <= 4; ++$i) {
            self::assertSame('Invalid username or password.', $this->failedSignInMessage('officer', 'Wrong-password-'.$i));
        }
        self::assertFalse($this->user('officer')->isLocked());

        $this->failedSignInMessage('officer', 'Wrong-password-5');
        self::assertTrue($this->user('officer')->isLocked());
        self::assertContains(UserAuditEntry::LOCKED, $this->auditActions('officer'));

        // Locked: even the right password is refused, with the same message as a wrong one.
        self::assertSame('Invalid username or password.', $this->failedSignInMessage('officer', self::PASSWORD));

        static::getContainer()->get(UserAdministration::class)->unlockDirectly($this->user('officer'), 'console:test');
        $this->signIn('officer', self::PASSWORD);
        self::assertResponseRedirects('/account/start');
        self::assertSame(0, $this->user('officer')->getFailedLoginCount());
    }

    public function testATemporaryPasswordMustBeReplacedBeforeAnythingElse(): void
    {
        $user = $this->createUser('officer', temporary: true);
        $this->signIn('officer', self::PASSWORD);
        $this->client->followRedirect();
        self::assertResponseRedirects('/account/password');
        $this->client->request('GET', '/cards');
        self::assertResponseRedirects('/account/password');

        $this->client->request('GET', '/account/password');
        self::assertSelectorTextContains('.exception', 'temporary password');

        $this->changePassword(self::PASSWORD, 'short');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-errors', 'Use 12 to 128 characters.');
        self::assertSelectorTextContains('.form-errors', 'Include an upper-case letter, a digit and a symbol.');

        $this->changePassword(self::PASSWORD, self::PASSWORD);
        self::assertSelectorTextContains('.form-errors', 'not used for your last 5 passwords');

        $this->changePassword(self::PASSWORD, 'My-officer-pass-9');
        self::assertSelectorTextContains('.form-errors', 'cannot contain your username');

        $this->changePassword('Wrong-current-1', 'A-brand-new-pass-9');
        self::assertSelectorTextContains('.form-errors', 'not your current password');

        $this->changePassword(self::PASSWORD, 'A-brand-new-pass-9');
        self::assertResponseRedirects('/account/start');
        $user = $this->user('officer');
        self::assertFalse($user->mustChangePassword());
        self::assertCount(1, $this->em()->getRepository(PasswordHistory::class)->findBy(['user' => $user]));
        self::assertContains(UserAuditEntry::PASSWORD_CHANGED, $this->auditActions('officer'));

        // Still signed in, and free to use the app.
        $this->client->request('GET', '/cards');
        self::assertResponseIsSuccessful();
    }

    public function testAnExpiredPasswordMustBeChanged(): void
    {
        $user = $this->loginAs('officer');
        $user->setPassword($user->getPassword(), $this->clock->now()->modify('-91 days'), false);
        $this->em()->flush();

        $this->client->request('GET', '/');
        self::assertResponseRedirects('/account/password');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.exception', 'older than 90 days');
    }

    public function testTheSessionEndsAfterFifteenIdleMinutes(): void
    {
        $this->loginAs('officer');
        $this->client->request('GET', '/account/password');
        self::assertResponseIsSuccessful();

        $this->clock->sleep(14 * 60);
        $this->client->request('GET', '/account/password');
        self::assertResponseIsSuccessful();

        // A background refresh is not activity: it neither keeps the session alive...
        $this->clock->sleep(10 * 60);
        $this->client->request('GET', '/account/password', server: ['HTTP_X_BACKGROUND_REFRESH' => '1']);
        self::assertResponseIsSuccessful();

        // ...nor resets the idle time (now 16 minutes since the last real request).
        $this->clock->sleep(6 * 60);
        $this->client->request('GET', '/account/password');
        self::assertResponseRedirects('/login?expired=1');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main form', 'Your session ended');
        self::assertContains(UserAuditEntry::SESSION_EXPIRED, $this->auditActions('officer'));

        $this->client->request('GET', '/cards');
        self::assertResponseRedirects('/login');
    }

    public function testTheSessionEndsEightHoursAfterSigningInEvenWhenActive(): void
    {
        $this->loginAs('officer');
        // loginAs() skips the sign-in form, so the session's start is its first request, 10 minutes in.
        for ($minutes = 0; $minutes <= 8 * 60 + 10; $minutes += 10) {
            $this->clock->sleep(10 * 60);
            $this->client->request('GET', '/account/password');
        }
        self::assertResponseRedirects('/login?expired=1');
    }

    public function testSigningInElsewhereEndsTheOtherSession(): void
    {
        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertResponseIsSuccessful();

        $user = $this->user('officer');
        $user->recordLogin('10.0.0.9', 'another-session', $this->clock->now());
        $this->em()->flush();

        $this->client->request('GET', '/cards');
        self::assertResponseRedirects('/login');
    }

    public function testDisablingAnAccountEndsItsSession(): void
    {
        $user = $this->loginAs('officer');
        $user->disable('admin', $this->clock->now());
        $this->em()->flush();

        $this->client->request('GET', '/cards');
        self::assertResponseRedirects('/login');
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function separationOfDuties(): iterable
    {
        yield 'administrator on migration pages' => ['admin', '/cards', 403];
        yield 'administrator on the overview' => ['admin', '/', 403];
        yield 'administrator on user administration' => ['admin', '/admin/users', 200];
        yield 'officer on user administration' => ['officer', '/admin/users', 403];
        yield 'approver on user administration' => ['approver', '/admin/audit', 403];
        yield 'approver on migration pages' => ['approver', '/migration', 200];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('separationOfDuties')]
    public function testEachRoleSeesOnlyItsOwnPages(string $user, string $url, int $status): void
    {
        $this->loginAs($user);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame($status);
    }

    public function testAdministratorsLandOnUserAdministration(): void
    {
        $this->createUser('admin');
        // Opening the overview while signed out must not send the administrator back there.
        $this->client->request('GET', '/');
        self::assertResponseRedirects('/login');
        $this->signIn('admin', self::PASSWORD);
        self::assertResponseRedirects('/account/start');
        $this->client->followRedirect();
        self::assertResponseRedirects('/admin/users');
    }

    public function testResponsesCarryHardeningHeaders(): void
    {
        $this->client->request('GET', '/login');
        $headers = $this->client->getResponse()->headers;

        self::assertSame('DENY', $headers->get('X-Frame-Options'));
        self::assertSame('nosniff', $headers->get('X-Content-Type-Options'));
        self::assertSame('same-origin', $headers->get('Referrer-Policy'));
        self::assertStringContainsString("frame-ancestors 'none'", $headers->get('Content-Security-Policy'));
        self::assertStringContainsString('no-store', $headers->get('Cache-Control'));
    }

    public function testDormantAccountsCannotSignInAndAreDisabled(): void
    {
        $user = $this->createUser('officer');
        $this->createUser('admin');
        $this->clock->sleep(91 * 86400);

        self::assertSame('Invalid username or password.', $this->failedSignInMessage('officer', self::PASSWORD));
        self::assertFalse($this->user('officer')->isLocked(), 'a refused dormant account is not a wrong password');

        $disabled = static::getContainer()->get(DisableDormantUsersHandler::class)(new DisableDormantUsers());
        self::assertSame(1, $disabled, 'the last user administrator is kept');
        self::assertFalse($this->user('officer')->isActive());
    }

    private function signIn(string $username, string $password): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => $username, '_password' => $password]);
    }

    private function failedSignInMessage(string $username, string $password): string
    {
        $this->signIn($username, $password);
        self::assertResponseRedirects('/login');

        return $this->client->followRedirect()->filter('form p[role=alert]')->text();
    }

    private function changePassword(string $current, string $new): void
    {
        $this->client->request('GET', '/account/password');
        $this->client->submitForm('Change password', [
            'change_password[currentPassword]' => $current,
            'change_password[newPassword][first]' => $new,
            'change_password[newPassword][second]' => $new,
        ]);
    }

    /** @return list<string> */
    private function auditActions(string $target): array
    {
        $entries = $this->em()->getRepository(UserAuditEntry::class)->findBy(['target' => $target], ['id' => 'ASC']);

        return array_map(static fn (UserAuditEntry $e) => $e->getAction(), $entries);
    }
}
