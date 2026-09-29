# Pending tasks

## Fix the failing batch workflow test

`BatchWorkflowTest::testTheValidRowsOfABatchThatNeedsCorrectionCanProceedWithoutTheRest`
(`tests/Controller/BatchWorkflowTest.php`, the assertion on the renames, around line 307) fails:
it expects `001-005688102` to be renamed before `001-009713450`, but they are renamed the other way
round. It already failed before the recent UI work, so it is not caused by it.

Within a chunk, `BatchWorkflow::applyPending()` takes accounts in account-number order
(`MigrationRowRepository::nextPendingAccounts()`), but renames them in file order: `pendingRowsFor()`
returns rows by line number and `MappingValidator::validate()` makes one plan per account in that
order. In the tests `app.batch.apply_chunk` is 2, so both accounts fall in one chunk.

Find out which order is intended (the history of the test, `BatchWorkflow`, `MigrationRowRepository`
and `MappingValidator`), then fix either the test's expectation or the code, and run
`php bin/phpunit`.

# Measured and set aside

## Batching for the account replacement apply step (not worth doing)

The idea: validation now commits its app-database writes in groups of 10,000 rows
(`CHUNKS_PER_COMMIT` in `src/Migration/BatchImporter.php`), which removed its slowdown past a couple
of hundred thousand rows. Do the same for the apply step (`BatchWorkflow::applyPending()`), which
renames `app.batch.apply_chunk` (500) accounts per chunk, re-validates each chunk under row locks,
commits it in its own core transaction, then marks its rows applied (`migration_row.applied_at`) and
updates the batch counters in one app commit per chunk. The expectation was that those app commits
would slow down on large batches the same way validation did.

Measured on 2026-09-29: 220,000 rows (200,000 accounts, 400 chunks) validated, approved and applied
end to end on a local MySQL 9.7.1 holding synthetic core data at the core server's table sizes
(`prmaster` 750k, `prlinkxx` 340k, `customer` 256k), with core's own `prlinkxx` update trigger and
`sp_insertlogclixx`. Every SQL statement and commit was timed per chunk.

| Step (average ms per chunk) | Chunks 1–50 | 101–150 | 201–250 | 351–400 | Share of run |
|---|---|---|---|---|---|
| Core rename writes (`prmaster`, `prlinkxx` + audit trigger, log call) | 497 | 483 | 453 | 475 | 61% |
| PHP, hydration, lock | 118 | 111 | 105 | 113 | 14.5% |
| Core re-validation under row locks | 113 | 99 | 95 | 105 | 13% |
| App: mark rows applied | 32 | 30 | 34 | 32 | 4% |
| App: first mappings and loading the chunk's rows | 34 | 30 | 29 | 31 | 4% |
| Core commit | 13 | 13 | 18 | 15 | 2% |
| App: batch counters flush and commit | 5.0 | 4.5 | 4.6 | 4.9 | 0.6% |
| **Whole chunk** | **814** | **771** | **740** | **777** | 304 s in all |

Findings:

- **No slowdown as the batch grows.** The last chunks cost the same as the first. Unlike validation,
  which inserted rows into indexes in no particular order while the table grew, marking rows applied
  only moves existing index entries, chunk by chunk in account order.
- **Grouping would save about 2 seconds.** The app-side bookkeeping is 4.6% of the run (about 14 s),
  and grouping only removes its commits (about 4 ms each, 400 in all); the updates themselves remain.
  That is not worth the added risk of the app record falling behind core when a run stops mid-group.
- **The time is in core:** three statements per account, plus core's audit trigger on every link.
  On the real server each of these also pays a network round trip, so in production the balance tips
  further towards core. If the apply step ever needs to be faster, that is where to look.

Caveats: MySQL 9.7.1 on this machine's local disk rather than the 8.0.31 core server over the network,
synthetic data, and `migration_row` holding only this batch. Re-measure on production-like hardware
before reopening this.
