<?php

namespace App\Tests\Controller;

use App\Entity\UserAuditEntry;
use App\Tests\AppTestCase;

/** Administration > Settings: the theme and the loading animation, set for everyone (App\Controller\SettingsController). */
final class SettingsControllerTest extends AppTestCase
{
    public function testAnAdministratorSetsTheLoadingAnimationForEveryone(): void
    {
        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('.directory-panel > .loading-indicator[data-animation="rocket"] svg.rocket', 'The rocket is the default');

        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="app_settings[loadingAnimation]"][value="rocket"][checked]');
        self::assertSelectorExists('.loader-option .loading-indicator[data-animation="dinosaur"] svg.dino', 'Each option has a live preview');

        $this->client->submitForm('Save', ['app_settings[loadingAnimation]' => 'dinosaur']);
        self::assertResponseRedirects('/admin/settings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Everyone now sees the dinosaur');
        self::assertSelectorExists('input[name="app_settings[loadingAnimation]"][value="dinosaur"][checked]');

        $entry = $this->em()->getRepository(UserAuditEntry::class)->findOneBy(['action' => UserAuditEntry::SETTING_CHANGED]);
        self::assertSame('admin', $entry?->getActor());
        self::assertSame('settings', $entry->getTarget());
        self::assertSame('Loading animation: Dinosaur (was Rocket)', $entry->getDetails());

        // Every loading indicator follows, for every user: in panels and in the full-page overlay.
        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('.directory-panel > .loading-indicator[data-animation="dinosaur"] svg.dino');
        self::assertSelectorExists('.page-loader .loading-indicator[data-animation="dinosaur"]');
        self::assertSelectorNotExists('.loading-indicator svg.rocket');
    }

    public function testTheThemeIsOnEveryPageIncludingTheSignInPage(): void
    {
        $this->client->request('GET', '/login');
        self::assertSelectorExists('html[data-app-theme="space"]', 'Space is the default');

        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('html[data-app-theme="space"]');

        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorExists('input[name="app_settings[theme]"][value="space"][checked]');
        self::assertSelectorExists('.loader-option .theme-swatch[data-app-theme="space"]', 'Each theme has a preview');
    }

    public function testSavingTheSettingsInUseChangesNothing(): void
    {
        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        $this->client->submitForm('Save', ['app_settings[theme]' => 'space', 'app_settings[loadingAnimation]' => 'rocket']);
        $this->client->followRedirect();

        self::assertSelectorTextContains('.flash.success', 'Nothing changed');
        self::assertNull($this->em()->getRepository(UserAuditEntry::class)->findOneBy(['action' => UserAuditEntry::SETTING_CHANGED]));
    }

    public function testOnlyUserAdministratorsCanChangeSettings(): void
    {
        $this->loginAs('officer');
        $this->client->request('GET', '/admin/settings');
        self::assertResponseStatusCodeSame(403);
    }
}
