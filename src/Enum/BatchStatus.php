<?php

namespace App\Enum;

enum BatchStatus: string
{
    /** Uploaded, but at least one row failed validation. Cannot be submitted. */
    case Invalid = 'invalid';
    /** Every row passed validation; the uploader can submit it for approval. */
    case Validated = 'validated';
    case AwaitingApproval = 'awaiting_approval';
    case Rejected = 'rejected';
    /** Approved and being applied to core. A batch left here needs manual reconciliation. */
    case Processing = 'processing';
    case Completed = 'completed';
    /** Approved, but applying it failed; nothing was changed in core. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Invalid => 'Needs correction',
            self::Validated => 'Ready to submit',
            self::AwaitingApproval => 'Awaiting approval',
            self::Rejected => 'Rejected',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /** CSS tag modifier used by the templates. */
    public function tone(): string
    {
        return match ($this) {
            self::Completed => 'success',
            self::Invalid, self::Failed, self::Rejected => 'danger',
            self::AwaitingApproval, self::Processing => 'warning',
            self::Validated => 'neutral',
        };
    }
}
