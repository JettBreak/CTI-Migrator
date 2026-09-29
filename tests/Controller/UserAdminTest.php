<?php

namespace App\Tests\Controller;

use App\Entity\UserAuditEntry;
use App\Entity\UserChangeRequest;
use App\Enum\UserChangeStatus;
use App\Enum\UserRole;
use App\Tests\AppTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** User administration: every change is requested by one administrator and approved by another. */
final class UserAdminTest extends AppTestCase
{
    public function testANewAccountExistsOnlyOnceASecondAdministratorApprovesIt(): void
    {
        $this->createUser('admin2');
        $this->loginAs('admin1');
        $this->client->request('GET', '/admin/new-user');
        $this->client->submitForm('Request account', [
            'new_account[username]' => 'newbie',
            'new_account[displayName]' => 'New Person',
            'new_account[role]' => UserRole::Approver->value,
            'new_account[reason]' => 'Ticket OPS-42',
        ]);
        self::assertResponseRedirects('/admin/users');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Account "newbie" requested');
        self::assertNull(static::getContainer()->get('App\Repository\UserRepository')->findOneByUsername('newbie'));

        // The requester cannot approve their own request.
        self::assertSelectorTextContains('.request-row', 'Waiting for another administrator');
        self::assertCount(0, $crawler->filter('form[action$="/approve"]'));
        $this->client->request('POST', sprintf('/admin/requests/%d/approve', $this->pending()->getId()), ['_token' => $this->token($crawler)]);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs('admin2');
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action$="/approve"]')->form());
        self::assertResponseRedirects('/admin/users/newbie');
        $crawler = $this->client->followRedirect();
        $password = $crawler->filter('.credential-value')->text();

        $user = $this->user('newbie');
        self::assertSame(UserRole::Approver, $user->getRole());
        self::assertTrue($user->mustChangePassword());
        self::assertSame('admin1', $user->getCreatedBy());

        // Shown once only.
        $this->client->request('GET', '/admin/users/newbie');
        self::assertSelectorNotExists('.credential-value');

        // The new user signs in with it and has to choose their own password first.
        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => 'newbie', '_password' => $password]);
        $this->client->followRedirect();
        self::assertResponseRedirects('/account/password');
    }

    public function testAdministratorsCannotChangeTheirOwnAccount(): void
    {
        $this->createUser('officer');
        $this->loginAs('admin1');
        $this->client->request('GET', '/admin/users/admin1');
        self::assertSelectorTextContains('main', 'You cannot change your own account');
        self::assertSelectorNotExists('main form');

        $token = $this->token($this->client->request('GET', '/admin/users/officer'));
        $this->client->request('POST', '/admin/users/admin1/request', ['_token' => $token, 'type' => 'disable', 'reason' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testARoleChangeCanBeRejectedAndARequestWithdrawn(): void
    {
        $this->createUser('officer');
        $this->createUser('admin2');
        $this->loginAs('admin1');
        $this->requestChange('officer', ['type' => 'change_role', 'role' => UserRole::Approver->value, 'reason' => 'Promotion']);
        self::assertSelectorTextContains('.flash.success', 'Change role as Migration Approver requested');

        // Only one pending change per account.
        $this->requestChange('officer', ['type' => 'disable', 'reason' => 'Left']);
        self::assertSelectorTextContains('.flash.error', 'already waiting for approval');

        $this->loginAs('admin2');
        $crawler = $this->client->request('GET', '/admin/users/officer');
        $this->client->submit($crawler->filter('form[action$="/reject"]')->form(['note' => 'Not yet']));
        self::assertSame(UserRole::Officer, $this->user('officer')->getRole());
        self::assertSame(UserChangeStatus::Rejected, $this->em()->getRepository(UserChangeRequest::class)->findOneBy([])->getStatus());

        $this->requestChange('officer', ['type' => 'disable', 'reason' => 'Left the team']);
        $crawler = $this->client->request('GET', '/admin/users');
        $this->client->submit($crawler->filter('form[action$="/cancel"]')->form());
        self::assertNull($this->pending());
        self::assertTrue($this->user('officer')->isActive());
    }

    public function testAnApprovedPasswordResetUnlocksAndSignsTheAccountOut(): void
    {
        $officer = $this->createUser('officer');
        for ($i = 0; $i < 5; ++$i) {
            $officer->recordFailedLogin(5, $this->clock->now());
        }
        $officer->recordLogin('10.0.0.1', 'officer-session', $this->clock->now());
        $this->em()->flush();
        $this->createUser('admin2');

        $this->loginAs('admin1');
        $this->requestChange('officer', ['type' => 'reset_password', 'reason' => 'Forgot it']);
        $this->loginAs('admin2');
        $crawler = $this->client->request('GET', '/admin/users/officer');
        $this->client->submit($crawler->filter('form[action$="/approve"]')->form());
        $this->client->followRedirect();
        self::assertSelectorExists('.credential-value');

        $officer = $this->user('officer');
        self::assertFalse($officer->isLocked());
        self::assertTrue($officer->mustChangePassword());
        self::assertNull($officer->getSessionToken());
    }

    public function testTheAccountPageShowsPasswordAgeAndOneActionTilePerForm(): void
    {
        $this->createUser('officer');
        $this->clock->sleep(80 * 86400);
        $this->loginAs('admin1');
        $crawler = $this->client->request('GET', '/admin/users/officer');

        self::assertSelectorTextContains('.user-rail h1', $this->user('officer')->getDisplayName());
        self::assertSelectorTextContains('.password-age', '80 of 90 days');
        self::assertSelectorTextContains('.password-age', 'Expires in 10 days');
        self::assertSelectorExists('.password-age.ageing');
        // Tiles open the forms by position, so each tile needs exactly one form.
        self::assertSame(['Change role', 'Reset password', 'Disable'], $crawler->filter('.action-tile b')->each(static fn ($b) => $b->text()));
        self::assertCount(3, $crawler->filter('.account-actions form[data-tabs-target="panel"]'));
    }

    public function testALockedAccountCanBeUnlockedAtOnce(): void
    {
        $officer = $this->createUser('officer');
        for ($i = 0; $i < 5; ++$i) {
            $officer->recordFailedLogin(5, $this->clock->now());
        }
        $this->em()->flush();

        $this->loginAs('admin1');
        $crawler = $this->client->request('GET', '/admin/users/officer');
        $this->client->submit($crawler->filter('form[action$="/unlock"]')->form());
        self::assertFalse($this->user('officer')->isLocked());

        $actions = array_map(static fn (UserAuditEntry $e) => $e->getAction(), $this->em()->getRepository(UserAuditEntry::class)->findBy(['target' => 'officer']));
        self::assertContains(UserAuditEntry::UNLOCKED, $actions);
    }

    public function testTheConsoleCreatesTheFirstAdministratorAndGuardsTheLastOne(): void
    {
        $application = new Application(static::$kernel);

        $create = new CommandTester($application->find('app:user:create'));
        self::assertSame(Command::SUCCESS, $create->execute(['username' => 'root.admin', '--name' => 'Root Admin', '--role' => 'admin']));
        self::assertMatchesRegularExpression('/Temporary password .*: \S{16}/', $create->getDisplay());
        self::assertSame(UserRole::Admin, $this->user('root.admin')->getRole());
        self::assertSame(Command::INVALID, $create->execute(['username' => 'x.y', '--name' => 'X', '--role' => 'boss']));

        $account = new CommandTester($application->find('app:user:account'));
        self::assertSame(Command::FAILURE, $account->execute(['action' => 'disable', 'username' => 'root.admin']));
        self::assertStringContainsString('last active user administrator', $account->getDisplay());
    }

    /** @param array<string, string> $fields */
    private function requestChange(string $username, array $fields): void
    {
        $crawler = $this->client->request('GET', '/admin/users/'.$username);
        $this->client->request('POST', sprintf('/admin/users/%s/request', $username), ['_token' => $this->token($crawler)] + $fields);
        self::assertResponseRedirects('/admin/users/'.$username);
        $this->client->followRedirect();
    }

    private function token(\Symfony\Component\DomCrawler\Crawler $crawler): string
    {
        return $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    private function pending(): ?UserChangeRequest
    {
        return $this->em()->getRepository(UserChangeRequest::class)->findOneBy(['status' => UserChangeStatus::Pending]);
    }
}
