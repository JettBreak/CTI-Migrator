<?php

namespace App\Enum;

enum BatchStatus: string
{
    /** Uploaded; the file is being read and validated (by the background worker for large files). */
    case Importing = 'importing';
    /** Uploaded, but at least one row failed validation. Cannot be submitted. */
    case Invalid = 'invalid';
    /** Every row passed validation; the uploader can submit it for approval. */
    case Validated = 'validated';
    case AwaitingApproval = 'awaiting_approval';
    case Rejected = 'rejected';
    /** Approved and being applied to core, chunk by chunk. A batch left here needs manual reconciliation. */
    case Processing = 'processing';
    case Completed = 'completed';
    /** Validating or applying it failed before anything was changed in core. */
    case Failed = 'failed';
    /** Applying stopped part-way: earlier chunks are renamed in core, the rest are not. An approver can resume it. */
    case Halted = 'halted';
    /** A migration officer asked for the replaced accounts to get their old numbers back; an approver decides. */
    case RollbackRequested = 'rollback_requested';
    /** Rollback approved and being carried out in core, chunk by chunk. */
    case RollingBack = 'rolling_back';
    /** Every replaced account that still matched got its old number back; the rest were skipped and exported. */
    case RolledBack = 'rolled_back';
    /** Rolling back stopped part-way on an unexpected error; an approver can resume it. */
    case RollbackHalted = 'rollback_halted';

    public function label(): string
    {
        return match ($this) {
            self::Importing => 'Validating',
            self::Invalid => 'Needs correction',
            self::Validated => 'Ready to submit',
            self::AwaitingApproval => 'Awaiting approval',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Halted => 'Stopped part-way',
            self::RollbackRequested => 'Rollback requested',
            self::RollingBack => 'Rolling back',
            self::RolledBack => 'Rolled back',
            self::RollbackHalted => 'Rollback stopped part-way',
        };
    }

    /** CSS tag modifier used by the templates. */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Invalid, self::Failed, self::Rejected, self::Halted, self::RollbackHalted => 'danger',
            self::AwaitingApproval, self::Processing, self::Importing, self::RollbackRequested, self::RollingBack => 'warning',
            self::Validated, self::RolledBack => 'neutral',
        };
    }

    /** Work is under way (in the request or the background worker); the page shows progress. */
    public function busy(): bool
    {
        return self::Importing === $this || self::Processing === $this || self::RollingBack === $this;
    }
}
