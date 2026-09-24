<?php

namespace App\Tests\Controller;

use App\Entity\ExportJob;
use App\Enum\ExportState;
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
        self::assertSelectorTextContains('tbody', '5 / ~5 rows');

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
        self::assertSelectorExists('meta[http-equiv="refresh"]');

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
