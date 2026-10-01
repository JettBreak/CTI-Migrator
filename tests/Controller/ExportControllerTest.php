<?php

namespace App\Tests\Controller;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use App\Service\CardExport;
use App\Tests\AppTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * In the test environment exports over 2 rows are queued, and the queue runs inline (sync transport).
 */
final class ExportControllerTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        (new Filesystem())->remove(static::getContainer()->getParameter('app.export.dir'));
        $this->loginAs('officer');
    }

    public function testSmallExportDownloadsImmediately(): void
    {
        $this->exportFrom('/cards?status=Restricted');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('text/csv', $this->client->getResponse()->headers->get('content-type') ?? '');
        $lines = explode("\n", trim($this->client->getInternalResponse()->getContent()));
        self::assertSame('card_ref,card_number,cardholder,customer_id,current_account,new_account', $lines[0]);
        self::assertCount(2, $lines);
        self::assertStringStartsWith('1004,"5412 86•• •••• 4094","Carlo M. Navarro"', $lines[1]);
        self::assertSame(0, $this->jobCount(), 'no background job for a small export');
    }

    public function testLargeExportIsPreparedInTheBackgroundAndDownloadable(): void
    {
        $this->exportFrom('/cards');

        self::assertResponseRedirects('/exports', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'is being prepared');
        self::assertSelectorTextContains('tbody', 'All statuses');
        self::assertSelectorTextContains('tbody', 'Completed');
        self::assertSelectorTextContains('tbody', '5 cards');

        $job = $this->latestJob();
        self::assertSame(ExportState::Completed, $job->getState());
        self::assertSame(100, $job->progress());

        $this->client->request('GET', sprintf('/exports/%d/download', $job->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString(sprintf('card-account-source-%d.csv', $job->getId()), $this->client->getResponse()->headers->get('content-disposition') ?? '');
        $csv = file_get_contents($this->client->getResponse()->getFile()->getPathname());
        $lines = explode("\n", trim($csv));
        self::assertCount(6, $lines);
        self::assertSame('1001,"5412 86•• •••• 8821","Maria L. Santos",CUS-100284,001-004568921,', $lines[1]);
    }

    public function testExportsFollowTheStatusFilter(): void
    {
        $this->exportFrom('/cards?status=Active');
        self::assertResponseRedirects('/exports', 303);

        $job = $this->latestJob();
        self::assertSame('Active', $job->getStatusFilter());
        self::assertSame(4, $job->getCardsWritten());
        self::assertSame(4, $job->getRowsWritten());
        self::assertSame(sprintf('card-account-source-active-%d.csv', $job->getId()), $job->downloadName());
    }

    public function testUnfinishedExportsCannotBeDownloaded(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = new ExportJob('officer', null, 100);
        $em->persist($job);
        $em->flush();

        $this->client->request('GET', '/exports');
        self::assertSelectorTextContains('tbody', 'Queued');
        self::assertSelectorExists('[data-controller="auto-refresh"]');
        self::assertSelectorNotExists('meta[http-equiv="refresh"]');

        $this->client->request('GET', sprintf('/exports/%d/download', $job->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testARunningExportShowsItIsBeingPreparedInsteadOfADownload(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = new ExportJob('officer', null, 100);
        $job->start();
        $em->persist($job);
        $em->flush();

        $this->client->request('GET', '/exports');
        self::assertSelectorExists('tbody .download-pending[aria-disabled="true"] .loading-indicator.compact.is-active[role="status"]');
        // No visible label, but screen readers still hear what is happening.
        self::assertSelectorTextContains('tbody .download-pending .loading-label.visually-hidden', 'Preparing export');
        self::assertSelectorNotExists(sprintf('a[href="/exports/%d/download"]', $job->getId()));
    }

    public function testAQueuedExportSaysWhatItIsWaitingFor(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $queued = new ExportJob('officer', null, 100);
        $em->persist($queued);
        $em->flush();

        // The exports worker is off.
        $this->client->request('GET', '/exports');
        self::assertSelectorTextContains('tbody .export-wait', 'Waiting for the background worker to be turned on');

        // On, and free: it starts any moment.
        $this->client->request('GET', '/worker');
        $token = $this->client->getCrawler()->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', '/worker/switch', ['_token' => $token, 'on' => '1']);
        $this->client->request('GET', '/exports');
        self::assertSelectorTextContains('tbody .export-wait', 'Starting shortly');

        // On, and preparing another export: exports run one at a time on their own worker, never behind a batch.
        $running = new ExportJob('officer', null, 100);
        $running->start();
        $em->persist($running);
        $em->flush();
        $this->client->request('GET', '/exports');
        self::assertSelectorCount(1, 'tbody .export-wait');
        self::assertSelectorTextContains('tbody .export-wait', \sprintf('Waiting for export #%d to finish', $running->getId()));
    }

    public function testExportButtonsAreDisabledWhileAnExportIsRunning(): void
    {
        foreach (['/', '/cards', '/migration', '/exports'] as $page) {
            $this->client->request('GET', $page);
            self::assertSelectorExists('form[action$="/exports"] button:not([disabled])', $page.' with no export running');
        }

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $job = new ExportJob('approver', null, 100);
        $job->start();
        $em->persist($job);
        $em->flush();

        foreach (['/', '/cards', '/migration', '/exports'] as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertSelectorNotExists('form[action$="/exports"] button:not([disabled])', $page);
            self::assertStringContainsString(sprintf('Export #%d is running', $job->getId()), $crawler->filter('form[action$="/exports"]')->attr('title'), $page);
        }

        // A page opened before the export started still has an enabled button: the server refuses too.
        $token = $this->client->getCrawler()->filter('form[action$="/exports"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/exports', ['_token' => $token]);
        self::assertResponseRedirects('/exports', 303);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.error', sprintf('Export #%d is still running', $job->getId()));
        self::assertSame(1, $this->jobCount(), 'no second export was queued');
    }

    public function testAJustFinishedExportShowsItsBarBurningOutButAFailedOneDoesNot(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $done = new ExportJob('officer', null, 100);
        $done->start();
        $done->complete();
        $failed = new ExportJob('officer', null, 100);
        $failed->start();
        $failed->fail('Core unreachable');
        $em->persist($done);
        $em->persist($failed);
        $em->flush();

        $crawler = $this->client->request('GET', '/exports');
        self::assertCount(1, $crawler->filter('.progress-track.slim.is-done'));
        // It leaves by itself when its 15 seconds are up (just finished: nearly all of them left).
        self::assertMatchesRegularExpression('/--leave-after: 1[0-5]s/', $crawler->filter('.progress-track.is-done')->attr('style'));
        self::assertCount(1, $crawler->filter('.progress-track'));
    }

    public function testNoDownloadIsOfferedOnceTheFileIsGone(): void
    {
        $this->exportFrom('/cards');
        $job = $this->latestJob();
        $this->client->request('GET', '/exports');
        self::assertSelectorExists(sprintf('a[href="/exports/%d/download"]', $job->getId()));

        unlink(static::getContainer()->get(CardExport::class)->pathFor($job));

        $this->client->request('GET', '/exports');
        self::assertSelectorNotExists(sprintf('a[href="/exports/%d/download"]', $job->getId()));
        self::assertSelectorTextContains('tbody', 'File no longer available');

        $this->client->request('GET', sprintf('/exports/%d/download', $job->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testExportRequiresACsrfToken(): void
    {
        $this->client->request('POST', '/exports', ['_token' => 'forged']);
        self::assertResponseRedirects('/login');
        self::assertSame(0, $this->jobCount());
    }

    private function exportFrom(string $page): void
    {
        $form = $this->client->request('GET', $page)->filter('form[action$="/exports"]')->form();
        $this->client->submit($form);
    }

    private function latestJob(): ExportJob
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository(ExportJob::class)->findOneBy([], ['id' => 'DESC']);
    }

    private function jobCount(): int
    {
        return static::getContainer()->get(EntityManagerInterface::class)->getRepository(ExportJob::class)->count([]);
    }
}
