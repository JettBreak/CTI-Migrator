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
        self::assertSelectorNotExists('.field-icon, .field-reveal', 'Field icons and the password toggle are the corporate theme\'s');

        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('html[data-app-theme="space"]');

        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorExists('input[name="app_settings[theme]"][value="space"][checked]');
        self::assertSelectorExists('.loader-option .theme-swatch[data-app-theme="space"]', 'Each theme has a preview');
    }

    public function testAnAdministratorSwitchesEveryoneToTheEightBitTheme(): void
    {
        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorNotExists('link[href*="theme-8bit"]', 'Space has no stylesheet of its own');
        self::assertSelectorExists('.loader-option .theme-swatch[data-app-theme="8bit"]');

        $this->client->submitForm('Save', ['app_settings[theme]' => '8bit', 'app_settings[loadingAnimation]' => 'rocket']);
        self::assertResponseRedirects('/admin/settings');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Everyone now sees the 8-bit console theme');
        self::assertSelectorExists('html[data-app-theme="8bit"] link[href*="theme-8bit"]');

        $entry = $this->em()->getRepository(UserAuditEntry::class)->findOneBy(['action' => UserAuditEntry::SETTING_CHANGED]);
        self::assertSame('Theme: 8-bit console (was Space)', $entry?->getDetails());

        // Nothing of space: the rocket gives way to NOW LOADING, and the rocket animation is marked as not in this theme.
        self::assertSelectorExists('.loader-option:has(input[value="rocket"]) .tag', 'Not in the 8-bit console theme');
        self::assertSelectorExists('.loader-option:has(input[value="now-loading"]) .tag.success');

        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('.directory-panel > .loading-indicator[data-animation="now-loading"] .nl-bar');
        self::assertSelectorNotExists('.loading-indicator svg.rocket');
        self::assertSelectorNotExists('canvas[data-controller="starfield"]');
        self::assertSelectorExists('template#rocket-swipe[data-style="blocks"]', 'Signing out dissolves in blocks');

        $this->client->restart(); // signed out
        $this->client->request('GET', '/login');
        self::assertSelectorExists('html[data-app-theme="8bit"] link[href*="theme-8bit"]', 'The sign-in page too');
        self::assertSelectorExists('html[data-page-loader-wait-for-value="pixel-city:ready"]');
        self::assertSelectorExists('canvas[data-controller="pixel-city"][data-pixel-city-time-zone-value]');
        self::assertSelectorNotExists('canvas[data-controller="login-globe"]');
        self::assertSelectorNotExists('.zoom-hint');
        self::assertSelectorExists('template#rocket-swipe[data-style="blocks"]');
    }

    public function testTheDeveloperConsoleThemeHasATerminalForScenery(): void
    {
        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorExists('.loader-option .theme-swatch[data-app-theme="developer"]');
        $this->client->submitForm('Save', ['app_settings[theme]' => 'developer', 'app_settings[loadingAnimation]' => 'rocket']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Everyone now sees the Developer console theme');
        self::assertSelectorExists('html[data-app-theme="developer"] link[href*="theme-developer"]');
        self::assertSelectorExists('link[href*="family=JetBrains+Mono"]');
        self::assertSelectorNotExists('link[href*="Press+Start"]', 'Only the theme\'s own fonts');

        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('.directory-panel > .loading-indicator[data-animation="terminal"] .term-progress', 'The terminal loader in place of the rocket');
        self::assertSelectorNotExists('canvas[data-controller="starfield"]');
        self::assertSelectorExists('template#rocket-swipe[data-style="lines"]', 'Signing out clears the screen line by line');

        $this->client->restart(); // signed out
        $this->client->request('GET', '/login');
        self::assertSelectorExists('html[data-page-loader-wait-for-value="boot-log:ready"]');
        self::assertSelectorExists('.boot-log[data-controller="boot-log"][data-boot-log-time-zone-value] pre[data-boot-log-target="output"]');
        self::assertSelectorNotExists('canvas');
        self::assertSelectorExists('template#rocket-swipe[data-style="lines"]');
    }

    public function testTheCorporateThemeHasAPlainSignInPageAndAPlainFade(): void
    {
        $this->loginAs('admin');
        $this->client->request('GET', '/admin/settings');
        self::assertSelectorExists('.loader-option .theme-swatch[data-app-theme="corporate"]');
        $this->client->submitForm('Save', ['app_settings[theme]' => 'corporate', 'app_settings[loadingAnimation]' => 'rocket']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Everyone now sees the Corporate theme');
        self::assertSelectorExists('html[data-app-theme="corporate"] link[href*="theme-corporate"]');
        self::assertSelectorExists('link[href*="family=Inter"]');

        $this->loginAs('officer');
        $this->client->request('GET', '/cards');
        self::assertSelectorExists('.directory-panel > .loading-indicator[data-animation="spinner"] .spin-ring', 'The spinner in place of the rocket');
        self::assertSelectorNotExists('canvas[data-controller="starfield"]');
        self::assertSelectorNotExists('template#rocket-swipe', 'Signing out just fades the overlay');

        $this->client->restart(); // signed out
        $this->client->request('GET', '/login');
        self::assertSelectorNotExists('html[data-page-loader-wait-for-value]', 'A still scene: nothing to wait for');
        self::assertSelectorTextContains('aside.signin-showcase[aria-hidden="true"]', 'Example batch', 'A colour field on the right, its batch marked as an example');
        self::assertSelectorNotExists('.boot-log');
        self::assertSelectorTextSame('main h1', 'Welcome back');
        self::assertSelectorTextContains('.signin-help', 'Contact your user administrator, or open Lockout.');
        self::assertSelectorTextSame('.signin-help a[href]', 'Lockout');
        self::assertSelectorCount(2, 'form[action="/login"] label > svg.field-icon');
        self::assertSelectorExists('label[data-controller="password-reveal"] input[name="_password"][data-password-reveal-target="input"] ~ button.field-reveal[tabindex="-1"][aria-pressed="false"][aria-label="Show password"]');
        self::assertSelectorNotExists('canvas');
        self::assertSelectorNotExists('template#rocket-swipe');
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
