<?php

namespace App\Enum;

/**
 * The one role each account has. Separation of duties: makers upload and request, approvers check,
 * and user administrators manage accounts only (they never see batches or core data).
 */
enum UserRole: string
{
    /** Maker: uploads batches, submits them, requests rollbacks. */
    case Officer = 'ROLE_MIGRATION_OFFICER';
    /** Checker: approves or rejects what an officer submitted. */
    case Approver = 'ROLE_MIGRATION_APPROVER';
    /** Manages accounts (maker-checker between administrators); no access to migration data. */
    case Admin = 'ROLE_USER_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::Officer => 'Migration Officer',
            self::Approver => 'Migration Approver',
            self::Admin => 'User Administrator',
        };
    }
}
