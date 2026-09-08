<?php

namespace App\Exceptions;

/** Safety constraint #8. Raised while JETGRID_READONLY=true. */
class ReadOnlyModeException extends JetGridSafetyException
{
    public function __construct(public readonly string $commandKey)
    {
        parent::__construct("Refused [{$commandKey}]: JETGRID_READONLY is enabled.");
    }

    public function userMessage(): string
    {
        return 'JetGrid is in read-only mode. All write operations are disabled globally. A god_mode user can lift this in Settings.';
    }
}
