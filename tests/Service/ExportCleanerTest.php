<?php

namespace App\Tests\Service;

use App\Entity\ExportJob;
use App\Enum\ExportState;
use App\Service\CardExport;
use App\Service\ExportCleaner;
use App\Tests\AppTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class ExportCleanerTest extends AppTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = static::getContainer()->getParameter('app.export.dir');
        (new Filesystem())->remove($this->dir);
        (new Filesystem())->mkdir($this->dir);
    }

    public function testRemovesExpiredAndOrphanedFilesButKeepsRecentOnes(): void
    {
        $old = $this->completedJob(finishedDaysAgo: 8);
        $recent = $this->completedJob(finishedDaysAgo: 2);
        $stuck = $this->job(ExportState::Running, createdHoursAgo: 3);
        $busy = $this->job(ExportState::Running, createdHoursAgo: 0);

        file_put_contents($this->dir.'/cards-9999.csv', 'orphan');             // no such job
        file_put_contents($this->dir.'/cards-500.csv.part', 'crashed');        // day-old partial file
        touch($this->dir.'/cards-500.csv.part', time() - 2 * 86400);
        file_put_contents($this->dir.'/cards-501.csv.part', 'in progress');    // fresh partial file

        $report = $this->cleaner()->run('test');

        self::assertSame(1, $report['expired']);
        self::assertSame(1, $report['interrupted']);
        self::assertSame(3, $report['files']); // old export + orphan + stale partial
        self::assertFileDoesNotExist($this->path($old));
        self::assertFileExists($this->path($recent));
        self::assertFileDoesNotExist($this->dir.'/cards-9999.csv');
        self::assertFileDoesNotExist($this->dir.'/cards-500.csv.part');
        self::assertFileExists($this->dir.'/cards-501.csv.part');

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(ExportState::Expired, $em->find(ExportJob::class, $old->getId())->getState());
        self::assertSame(ExportState::Completed, $em->find(ExportJob::class, $recent->getId())->getState());
        self::assertSame(ExportState::Failed, $em->find(ExportJob::class, $stuck->getId())->getState());
        self::assertSame(ExportState::Running, $em->find(ExportJob::class, $busy->getId())->getState());

        self::assertSame('test', $this->cleaner()->lastRun()['by']);
        self::assertSame(['files' => 2, 'bytes' => filesize($this->path($recent)) + strlen('in progress')], $this->cleaner()->usage());

        // Expired exports can no longer be downloaded.
        $this->loginAs('officer');
        $this->client->request('GET', sprintf('/exports/%d/download', $old->getId()));
        self::assertResponseStatusCodeSame(404);
    }

    public function testConsoleCommandRunsTheCleanup(): void
    {
        $this->completedJob(finishedDaysAgo: 30);

        $tester = new CommandTester((new Application(static::$kernel))->find('app:exports:cleanup'));
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Retention 7 days: 1 export(s) expired', $tester->getDisplay());
    }

    private function completedJob(int $finishedDaysAgo): ExportJob
    {
        $job = $this->job(ExportState::Completed, createdHoursAgo: $finishedDaysAgo * 24);
        $this->setField($job, 'finishedAt', new \DateTimeImmutable(sprintf('-%d days', $finishedDaysAgo)));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        file_put_contents($this->path($job), "card_ref\n1\n");

        return $job;
    }

    private function job(ExportState $state, int $createdHoursAgo): ExportJob
    {
        $job = new ExportJob('officer', null, 10);
        $this->setField($job, 'state', $state);
        $this->setField($job, 'createdAt', new \DateTimeImmutable(sprintf('-%d hours', $createdHoursAgo)));
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->persist($job);
        $em->flush();

        return $job;
    }

    private function setField(ExportJob $job, string $field, mixed $value): void
    {
        (new \ReflectionProperty(ExportJob::class, $field))->setValue($job, $value);
    }

    private function path(ExportJob $job): string
    {
        return static::getContainer()->get(CardExport::class)->pathFor($job);
    }

    private function cleaner(): ExportCleaner
    {
        return static::getContainer()->get(ExportCleaner::class);
    }
}
