<?php

namespace App\Enum;

/** An account change that one administrator requests and a different one approves. */
enum UserChangeType: string
{
    case Create = 'create';
    case ChangeRole = 'change_role';
    case Disable = 'disable';
    case Enable = 'enable';
    case ResetPassword = 'reset_password';

    public function label(): string
    {
        return match ($this) {
            self::Create => 'Create account',
            self::ChangeRole => 'Change role',
            self::Disable => 'Disable account',
            self::Enable => 'Enable account',
            self::ResetPassword => 'Reset password',
        };
    }
}
