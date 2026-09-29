# Pending tasks

## Add batching to the account replacement apply step

Validation now commits its app-database writes in groups of 10,000 rows (`CHUNKS_PER_COMMIT` in
`src/Migration/BatchImporter.php`), which removed the slowdown past a couple of hundred thousand rows.
Do the same for the apply step (`BatchWorkflow::applyPending()`).

How it works today: accounts are renamed `app.batch.apply_chunk` (500) per chunk. Each chunk is
re-validated under row locks and committed in its own core transaction, then its rows are marked
applied in the app database (`migration_row.applied_at`) and the batch counters updated, one app
commit per chunk. Those app commits touch the same account-number indexes as validation did, so
they are expected to slow down the same way on large batches.

Points to settle before changing it:

- **Core transactions stay per chunk.** Each core commit keeps row locks short and is what makes a
  halted batch resumable from its last committed chunk. Only the app-side bookkeeping should be
  grouped, not the core writes.
- **The app record must never fall behind core.** If app commits are grouped and the run stops
  mid-group, core has renamed accounts that `migration_row` does not yet show as applied. Resume must
  still find them (it re-checks core, so a renamed account shows up as already moved); prove that
  with a test that stops between a core commit and the grouped app commit.
- **Measure first**, on MySQL with a realistic batch (200,000+ rows), timing each step per chunk as
  was done for validation, to confirm where the time actually goes before and after.
- Progress on the batch page would move once per group instead of once per chunk.
