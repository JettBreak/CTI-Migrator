<?php

namespace App\Tests\Controller;

use App\Tests\AppTestCase;

final class MigrationControllerTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loginAs('officer');
    }

    public function testDashboardShowsLiveCounts(): void
    {
        $this->client->request('GET', '/');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Migration overview');
        self::assertSelectorTextContains('header', 'officer');
        self::assertSelectorTextSame('.metrics article:first-child strong', '5');
        self::assertSelectorTextContains('.recent', 'No batches yet.');
    }

    public function testCardDirectoryShowsOnlyMaskedCardNumbers(): void
    {
        $this->client->request('GET', '/cards');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', '5412 86•• •••• 8821');
        self::assertSelectorTextContains('.pagination', 'Showing 1–5 of 5 records');
        self::assertStringNotContainsString('5412 8600', $this->client->getResponse()->getContent() ?: '');
    }

    public function testCardDirectoryFiltersByStatusDescription(): void
    {
        $crawler = $this->client->request('GET', '/cards');
        self::assertSame(['All statuses', 'Active (4)', 'Restricted (1)'], $crawler->filter('select[name="status"] option')->each(static fn ($o) => $o->text()));

        $crawler = $this->client->request('GET', '/cards?status=Restricted');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Carlo M. Navarro');
        self::assertSelectorTextContains('.pagination', 'Showing 1–1 of 1 records');
        self::assertSame('Restricted', $crawler->filter('select[name="status"] option[selected]')->attr('value'));
        self::assertSame('Restricted', $crawler->filter('form[action$="/exports"] input[name="status"]')->attr('value'), 'the export button exports the filtered cards');

        $this->client->request('GET', '/cards?status=Closed');
        self::assertSelectorTextContains('tbody', 'No records with status “Closed”.');
    }

    public function testAccountDirectoryFiltersByStatusDescription(): void
    {
        $crawler = $this->client->request('GET', '/accounts?status=Active');
        self::assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('tbody tr'));
        self::assertSelectorTextNotContains('tbody', 'Carlo M. Navarro');
    }

    public function testAccountDirectoryIsAvailable(): void
    {
        $this->client->request('GET', '/accounts');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('tbody', '001-004568921');
    }

    public function testStatusFiltersAndPaginationShowALoadingStateWhileTheListReloads(): void
    {
        foreach (['/cards', '/accounts'] as $page) {
            $this->client->request('GET', $page);
            // The panel shows the loading state for the filter form's submit and for pagination clicks.
            self::assertSelectorExists('.directory-panel[data-controller="loading"][data-action="submit->loading#start click->loading#follow"]', $page);
            self::assertSelectorExists('.directory-panel form.filter[data-controller="autosubmit"] select[data-action="change->autosubmit#submit"]', $page);
            self::assertSelectorExists('.directory-panel .pagination', $page);
            self::assertSelectorExists('.directory-panel > .dino-loading[role="status"] svg.dino', $page);
            self::assertSelectorTextContains('.directory-panel > .dino-loading .dino-label', '/cards' === $page ? 'Loading cards' : 'Loading accounts', $page);
        }
    }

    public function testDirectoriesCanBeSearchedAndKeepTheSearchAcrossFilters(): void
    {
        $crawler = $this->client->request('GET', '/cards?q=santos');
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Maria L. Santos');
        self::assertInputValueSame('q', 'santos');
        // The status filter carries the search, and the status keeps it in the search form.
        self::assertSelectorExists('form.filter input[type="hidden"][name="q"][value="santos"]');

        $crawler = $this->client->request('GET', '/accounts?q=009713450');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSelectorTextContains('tbody', 'Jonathan D. Cruz');

        $this->client->request('GET', '/accounts?q=nobody&status=Active');
        self::assertSelectorTextContains('tbody', 'No records matching “nobody” with status “Active”');
        self::assertSelectorExists('form.search input[type="hidden"][name="status"][value="Active"]');
    }

    public function testNonGetRequestsAreRejected(): void
    {
        $this->client->request('POST', '/cards');
        self::assertResponseStatusCodeSame(405);
    }
}
