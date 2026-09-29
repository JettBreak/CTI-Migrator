<?php

namespace App\Tests\Controller;

use App\Entity\UserAuditEntry;
use App\Security\SystemLock;
use App\Tests\AppTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Bundle\FrameworkBundle\Console\Application;

final class SystemLockTest extends AppTestCase
{
    /** Matches APP_SUPERUSER_PASSWORD_HASH in .env.test. */
    private const SUPERUSER_PASSWORD = 'Super-user-pass-1';

    public function testAForcedLockClosesSignInAndSignsOutWhoIsWorking(): void
    {
        $this->createUser('officer');
        $this->loginAs('admin1');

        $this->lockWith(['action' => 'lock_now', 'reason' => 'Contract ended']);
        self::assertResponseRedirects('/login');

        // The signed-in administrator is signed out on their next request.
        $this->client->request('GET', '/admin/users');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('h1', 'is locked');
        self::assertSelectorTextContains('main', 'Reason: Contract ended');
        self::assertSelectorNotExists('input[name="_username"]');

        // A sign-in posted directly is refused as well.
        $this->client->request('POST', '/login', ['_username' => 'officer', '_password' => self::PASSWORD, '_csrf_token' => 'csrf-token']);
        $refused = $this->em()->getRepository(UserAuditEntry::class)->findOneBy(['action' => UserAuditEntry::LOGIN_FAILED, 'target' => 'officer']);
        self::assertSame('The system is locked.', $refused?->getDetails());
        self::assertNull($this->user('officer')->getLastLoginAt());

        self::assertContains(UserAuditEntry::SYSTEM_LOCKED, $this->auditActions());
    }

    public function testATimedLockCountsDownThenLocks(): void
    {
        $this->client->request('GET', '/login');
        $this->lockWith(['action' => 'lock_after_days', 'days' => 10, 'reason' => 'Trial period']);
        self::assertContains(UserAuditEntry::SYSTEM_LOCK_SCHEDULED, $this->auditActions());

        $this->client->request('GET', '/login');
        self::assertSelectorTextContains('.lock-countdown', '10 days left');
        self::assertSelectorExists('input[name="_username"]');

        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorTextContains('.lock-banner', '10 days left');
        self::assertSelectorNotExists('.lock-banner.urgent');

        // Days later the session has timed out, so sign in again.
        $this->clock->sleep(8 * 86400);
        $this->client->getCookieJar()->clear();
        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorTextContains('.lock-banner', '2 days left');
        self::assertSelectorExists('.lock-banner.urgent');

        $this->clock->sleep(2 * 86400);
        $this->client->getCookieJar()->clear();
        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'timed lock');
    }

    public function testTheSuperUserUnlocksAndSignInReopens(): void
    {
        $this->client->request('GET', '/login');
        $this->lockWith(['action' => 'lock_now', 'reason' => 'Maintenance']);

        $this->client->request('GET', '/system-lock');
        self::assertResponseRedirects('/system-lock/unlock');

        $this->client->request('GET', '/system-lock/unlock');
        $this->client->submitForm('Unlock', $this->credentials(['reason' => 'Contract renewed']));
        self::assertResponseRedirects('/login');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Sign-in is open again');
        self::assertSelectorExists('input[name="_username"]');
        self::assertContains(UserAuditEntry::SYSTEM_UNLOCKED, $this->auditActions());
    }

    public function testATimedLockCanBeCancelled(): void
    {
        $this->client->request('GET', '/login');
        $this->lockWith(['action' => 'lock_after_days', 'days' => 5, 'reason' => 'Trial']);
        $this->lockWith(['action' => 'cancel', 'reason' => 'Paid']);

        self::assertFalse(static::getContainer()->get(SystemLock::class)->status()->isScheduled());
        self::assertContains(UserAuditEntry::SYSTEM_LOCK_CANCELLED, $this->auditActions());
    }

    public function testWrongSuperUserCredentialsAreRefusedAuditedAndThrottled(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->lockWith(['action' => 'lock_now', 'reason' => 'x', 'password' => 'wrong']);
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('[role=alert]', 'Invalid super user name or password.');
        }
        $this->lockWith(['action' => 'lock_now', 'reason' => 'x']);
        self::assertSelectorTextContains('[role=alert]', 'Too many attempts');

        self::assertFalse(static::getContainer()->get(SystemLock::class)->status()->isLocked());
        $actions = $this->auditActions();
        self::assertContains(UserAuditEntry::SUPERUSER_FAILED, $actions);
        self::assertContains(UserAuditEntry::SUPERUSER_THROTTLED, $actions);
    }

    public function testLockNowIgnoresALeftoverNumberOfDays(): void
    {
        $this->lockWith(['action' => 'lock_now', 'days' => 99999, 'reason' => 'Maintenance']);
        self::assertResponseRedirects('/login');
        self::assertTrue(static::getContainer()->get(SystemLock::class)->status()->isLocked());
    }

    public function testATimedLockNeedsDaysInRange(): void
    {
        $this->lockWith(['action' => 'lock_after_days', 'days' => 0, 'reason' => 'Trial']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose between 1 and 3650 days.');
    }

    public function testATimedLockNeedsANumberOfDays(): void
    {
        $this->lockWith(['action' => 'lock_after_days', 'reason' => 'Trial']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter how many days');
    }

    public function testAnEditedLockFileKeepsTheSystemLocked(): void
    {
        $this->client->request('GET', '/login');
        $this->lockWith(['action' => 'lock_after_days', 'days' => 30, 'reason' => 'Trial']);

        // Pushing the date back without the key breaks the signature: fail closed.
        $file = static::getContainer()->getParameter('app.system_lock.file');
        $data = json_decode((string) file_get_contents($file), true);
        $data['state']['locksAt'] = '2099-01-01T00:00:00+08:00';
        file_put_contents($file, json_encode($data));

        $this->client->request('GET', '/login');
        self::assertSelectorTextContains('main', 'failed its integrity check');
    }

    public function testTheRecoveryCommandUnlocks(): void
    {
        $this->client->request('GET', '/login');
        $this->lockWith(['action' => 'lock_now', 'reason' => 'Maintenance']);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:system:unlock'));
        $tester->execute(['reason' => 'Super user password lost']);
        $tester->assertCommandIsSuccessful();

        self::assertFalse(static::getContainer()->get(SystemLock::class)->status()->isLocked());
    }

    /** @param array<string, mixed> $fields */
    private function lockWith(array $fields): void
    {
        $this->client->request('GET', '/system-lock');
        $this->client->submitForm('Apply', $this->credentials($fields));
    }

    /** @param array<string, mixed> $fields */
    private function credentials(array $fields): array
    {
        $values = [];
        foreach ($fields + ['username' => 'superuser', 'password' => self::SUPERUSER_PASSWORD] as $name => $value) {
            $values['system_lock['.$name.']'] = $value;
        }

        return $values;
    }

    /** @return list<string> */
    private function auditActions(): array
    {
        return array_map(static fn (UserAuditEntry $e) => $e->getAction(), $this->em()->getRepository(UserAuditEntry::class)->findBy(['target' => 'system']));
    }
}
