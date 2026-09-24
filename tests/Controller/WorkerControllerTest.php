<?php

namespace App\Tests\Controller;

use App\Tests\AppTestCase;
use App\Worker\InMemoryWorkerLauncher;
use App\Worker\WorkerHeartbeatListener;
use App\Worker\WorkerLauncher;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Messenger\EventListener\StopWorkerOnRestartSignalListener;

final class WorkerControllerTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cache()->clear();
        static::getContainer()->get('cache.messenger.restart_workers_signal')->clear();
        $this->loginAs('officer');
    }

    public function testSwitchStartsAndStopsTheWorker(): void
    {
        $crawler = $this->client->request('GET', '/worker');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('#worker-state', 'Stopped');
        self::assertNull($crawler->filter('input[name="on"]')->attr('checked'));

        // Switch on: a process is launched and reported as starting until its first heartbeat.
        $this->toggle(true);
        self::assertSame([4242 => true], $this->launcher()->processes);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Background worker is starting.');
        self::assertSelectorTextSame('#worker-state', 'Starting');
        self::assertNotNull($crawler->filter('input[name="on"]')->attr('checked'));

        // Switching on again does not start a second process.
        $this->toggle(true);
        self::assertCount(1, $this->launcher()->processes);

        // First heartbeat from the worker process.
        $this->heartbeat(4242, current: ['message' => 'GenerateCardExport', 'since' => new \DateTimeImmutable()]);
        $this->client->request('GET', '/worker');
        self::assertSelectorTextSame('#worker-state', 'Running');
        self::assertSelectorTextContains('.facts', 'PID 4242');
        self::assertSelectorTextContains('.facts', 'GenerateCardExport');

        // Switch off: a graceful stop is signalled; the worker finishes its current job first.
        $this->toggle(false);
        self::assertTrue(static::getContainer()->get('cache.messenger.restart_workers_signal')->getItem(StopWorkerOnRestartSignalListener::RESTART_REQUESTED_TIMESTAMP_KEY)->isHit());
        $this->client->followRedirect();
        self::assertSelectorTextSame('#worker-state', 'Stopping');
        self::assertSelectorExists('form[action$="/worker/force-stop"]');

        // Force stop kills the process.
        $this->post('/worker/force-stop');
        self::assertFalse($this->launcher()->processes[4242]);
        $this->client->followRedirect();
        self::assertSelectorTextSame('#worker-state', 'Stopped');
        self::assertSelectorTextNotContains('body', 'stopped unexpectedly');
    }

    public function testAWorkerThatDiesIsReportedAsCrashed(): void
    {
        $this->heartbeat(999); // the launcher knows no process 999: it is gone
        $this->client->request('GET', '/worker');
        self::assertSelectorTextSame('#worker-state', 'Stopped');
        self::assertSelectorTextContains('.exception', 'stopped unexpectedly');
    }

    public function testCleanupCanBeRunFromThePage(): void
    {
        $this->post('/worker/cleanup');
        $this->client->followRedirect();
        self::assertSelectorTextContains('.flash.success', 'Cleanup done: 0 export(s) expired');
        self::assertSelectorTextContains('body', 'by officer');
    }

    public function testControlsRequireACsrfToken(): void
    {
        $this->client->request('POST', '/worker/switch', ['on' => '1', '_token' => 'forged']);
        self::assertResponseRedirects('/login');
        self::assertSame([], $this->launcher()->processes);
    }

    private function toggle(bool $on): void
    {
        $this->post('/worker/switch', $on ? ['on' => '1'] : []);
    }

    /** @param array<string, string> $fields */
    private function post(string $url, array $fields = []): void
    {
        $crawler = $this->client->request('GET', '/worker');
        $token = $crawler->filter('input[name="_token"]')->first()->attr('value');
        $this->client->request('POST', $url, ['_token' => $token] + $fields);
        self::assertResponseStatusCodeSame(303);
    }

    /** @param array{message: string, since: \DateTimeImmutable}|null $current */
    private function heartbeat(int $pid, ?array $current = null): void
    {
        $now = new \DateTimeImmutable();
        $cache = $this->cache();
        $cache->save($cache->getItem(WorkerHeartbeatListener::KEY)->set([
            'pid' => $pid, 'startedAt' => $now, 'beatAt' => $now, 'transports' => ['async'], 'handled' => 3, 'failed' => 0,
            'current' => $current, 'last' => null, 'lastError' => null, 'stoppedAt' => null,
        ]));
    }

    private function cache(): CacheItemPoolInterface
    {
        return static::getContainer()->get('cache.app');
    }

    private function launcher(): InMemoryWorkerLauncher
    {
        return static::getContainer()->get(WorkerLauncher::class);
    }
}
