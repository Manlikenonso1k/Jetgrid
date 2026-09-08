<?php

namespace App\Exceptions;

/** Safety constraint #3. There is no path to an arbitrary shell command. */
class CommandNotWhitelistedException extends JetGridSafetyException
{
    public function __construct(public readonly string $commandKey)
    {
        parent::__construct("Command [{$commandKey}] is not in the whitelist.");
    }

    public function userMessage(): string
    {
        return "“{$this->commandKey}” is not a whitelisted command. JetGrid cannot execute arbitrary shell input.";
    }
}
