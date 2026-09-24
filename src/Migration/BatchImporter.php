<?php

namespace App\Migration;

use App\Entity\MigrationBatch;
use App\Entity\MigrationRow;
use App\Repository\MigrationRowRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Streams an uploaded mapping file into a batch a chunk at a time: validate the chunk against core,
 * then bulk-insert it with its results. Memory use stays flat whatever the file size.
 */
final class BatchImporter
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MappingFileParser $parser,
        private readonly MappingValidator $validator,
        private readonly MigrationRowRepository $rows,
        /** Debug mode only: Doctrine's log of every query, which would otherwise grow with the file. */
        #[Autowire(service: 'doctrine.debug_data_holder')] private readonly ?DebugDataHolder $queryLog = null,
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

        $chunk = [];
        foreach ($this->parser->rows($path) as $line) {
            $chunk[] = new MigrationRow($batch, $line['line'], $line['card_ref'], $line['current_account'], $line['new_account']);
            if (self::CHUNK === count($chunk)) {
                $this->store($batch, $chunk);
                $chunk = [];
            }
        }
        if ([] !== $chunk) {
            $this->store($batch, $chunk);
        }

        $batch->finishValidation($this->rows->stats($batch)['accounts']);
        $this->em->flush();
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

        $this->em->getConnection()->transactional(function () use ($batch, $chunk): void {
            $this->rows->insert($batch, $chunk);
            $batch->recordValidated(count($chunk), count(array_filter($chunk, static fn (MigrationRow $r) => !$r->isValid())));
            $this->em->flush();
        });
        $this->queryLog?->reset();
    }
}
