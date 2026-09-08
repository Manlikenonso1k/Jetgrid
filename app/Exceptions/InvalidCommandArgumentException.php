<?php

namespace App\Exceptions;

class InvalidCommandArgumentException extends JetGridSafetyException
{
    public function __construct(public readonly string $argument, public readonly string $value)
    {
        parent::__construct("Argument [{$argument}] rejected: value does not match its allowed pattern.");
    }

    public function userMessage(): string
    {
        return "The value supplied for “{$this->argument}” is not in the allowed format, so the command was not built.";
    }
}
