<?php

namespace App\Tests\Controller;

use App\Tests\AppTestCase;

/** The light/dark theme switch in the header of the signed-in pages (assets/controllers/theme_controller.js). */
final class ThemeSwitchTest extends AppTestCase
{
    public function testSignedInPagesOfferSystemLightAndDark(): void
    {
        $this->loginAs('officer');
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();

        self::assertSelectorCount(3, 'header .theme-switch[data-controller="theme"] button[data-theme-target="option"]');
        foreach (['system', 'light', 'dark'] as $choice) {
            self::assertSelectorExists(\sprintf('.theme-switch button[data-theme-choice-param="%s"][data-action="theme#choose"]', $choice));
        }
        // The choice is applied in the head, before the first paint.
        self::assertStringContainsString("localStorage.getItem('theme')", $this->client->getCrawler()->filter('head')->html());
    }

    public function testTheSignInPageKeepsItsOwnTheme(): void
    {
        $this->client->request('GET', '/login');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('.theme-switch');
    }
}
