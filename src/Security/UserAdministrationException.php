<?php

namespace App\Security;

/** An account change that is not allowed; the message is shown to the administrator. */
final class UserAdministrationException extends \RuntimeException
{
}
