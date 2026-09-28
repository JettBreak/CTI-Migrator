<?php

namespace App\Migration;

use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Stopwatch\Stopwatch;

/**
 * Keeps memory (and so speed) flat while one message validates or replaces hundreds of thousands of
 * rows chunk by chunk: call release() after every chunk.
 *
 * In debug mode Doctrine records every SQL statement twice, in its query log for the profiler and as
 * a timing in the Stopwatch; over a long run that grows by about 1 MB per chunk and would end in the
 * worker running out of memory. Symfony only resets them between messages. Outside debug mode both are
 * absent and release() only collects garbage.
 */
final class ChunkMemory
{
    public function __construct(
        #[Autowire(service: 'doctrine.debug_data_holder')] private readonly ?DebugDataHolder $queryLog = null,
        #[Autowire(service: 'debug.stopwatch')] private readonly ?Stopwatch $stopwatch = null,
    ) {
    }

    public function release(): void
    {
        $this->queryLog?->reset();
        // Safe mid-message: the tracing around it (messenger, event dispatcher) checks isStarted() or holds its events.
        $this->stopwatch?->reset();
        // Detached rows and their validation results form reference cycles; free them now, not whenever PHP gets round to it.
        gc_collect_cycles();
    }
}
