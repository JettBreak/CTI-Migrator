<?php

namespace App\Security;

/**
 * Tracks an authenticated console command's in-process child commands.
 *
 * This authorization never crosses a PHP process boundary: direct commands run
 * with --no-interaction are still refused by ConsoleAuthentication.
 */
final class ConsoleCommandAuthorization
{
    private int $internalCommandDepth = 0;

    public function beginInternalCommands(): void
    {
        ++$this->internalCommandDepth;
    }

    public function endInternalCommands(): void
    {
        $this->internalCommandDepth = max(0, $this->internalCommandDepth - 1);
    }

    public function permitsInternalCommand(): bool
    {
        return $this->internalCommandDepth > 0;
    }
}
