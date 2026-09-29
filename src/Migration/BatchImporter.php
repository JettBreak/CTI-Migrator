<?php

namespace App\Migration;

use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Repository\MigrationRowRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Streams an uploaded mapping file into a batch a chunk at a time: validate the chunk against core,
 * then bulk-insert it with its results. Memory use stays flat whatever the file size.
 *
 * Chunks are committed in groups (CHUNKS_PER_COMMIT), not one by one. Every row updates indexes keyed on
 * account numbers, which arrive in no particular order, so each commit writes out index pages from all
 * over the table; committing each 1,000-row chunk on its own made every commit dearer than the last as
 * the table grew, and validation slowed down badly past a couple of hundred thousand rows. A group of
 * chunks writes each page once. The batch's progress, read by other requests, moves once per group.
 */
final class BatchImporter
{
    private const CHUNK = 1000;
    /** Chunks per database transaction: 10,000 rows. */
    private const CHUNKS_PER_COMMIT = 10;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MappingFileParser $parser,
        private readonly MappingValidator $validator,
        private readonly MigrationRowRepository $rows,
        private readonly ChunkMemory $memory,
    ) {
    }

    /**
     * Validates and stores every row of $path, then settles the batch's status (Validated or Invalid).
     * Safe to re-run on a batch whose previous run was interrupted: it starts again from the first row.
     *
     * @throws InvalidMappingFile
     */
    public function import(MigrationBatch $batch, string $path): void
    {
        $this->rows->deleteForBatch($batch);
        $batch->startValidation();
        $this->em->flush();

        $connection = $this->em->getConnection();
        $connection->beginTransaction();
        try {
            $chunk = [];
            $uncommitted = 0;
            foreach ($this->parser->rows($path) as $line) {
                $chunk[] = new MigrationRow($batch, $line['line'], $line['card_ref'], $line['current_account'], $line['new_account']);
                if (self::CHUNK === count($chunk)) {
                    $this->store($batch, $chunk);
                    $chunk = [];
                    if (self::CHUNKS_PER_COMMIT === ++$uncommitted) {
                        $connection->commit();
                        $connection->beginTransaction();
                        $uncommitted = 0;
                    }
                }
            }
            if ([] !== $chunk) {
                $this->store($batch, $chunk);
            }

            $batch->finishValidation($this->rows->stats($batch)['accounts']);
            $this->em->flush();
            $connection->commit();
        } catch (\Throwable $e) {
            // An interrupted import starts again from the first row, so the rows of the open group can go.
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    /** @param list<MigrationRow> $chunk rows in file order, following every row already stored */
    private function store(MigrationBatch $batch, array $chunk): void
    {
        // Earlier chunks are already stored, so the first mapping of any account in this chunk is either there or here.
        [$firstForCurrent, $firstForNew] = $this->rows->firstMappings(
            $batch,
            array_map(static fn (MigrationRow $r) => $r->getCurrentAccount(), $chunk),
            array_map(static fn (MigrationRow $r) => $r->getNewAccount(), $chunk),
        );
        $this->validator->validate($chunk, firstForCurrent: $firstForCurrent, firstForNew: $firstForNew);

        // Inside import()'s transaction for this group of chunks.
        $this->rows->insert($batch, $chunk);
        $batch->recordValidated(count($chunk), count(array_filter($chunk, static fn (MigrationRow $r) => !$r->isValid())));
        $this->em->flush();
        $this->memory->release();
    }
}
