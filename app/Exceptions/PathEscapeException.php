<?php

namespace App\Exceptions;

/** Safety constraint #2. Raised when a path resolves outside its allowed root. */
class PathEscapeException extends JetGridSafetyException
{
    public function __construct(public readonly string $path, public readonly string $root)
    {
        parent::__construct("Path [{$path}] resolves outside allowed root [{$root}].");
    }

    public function userMessage(): string
    {
        return 'That path resolves outside the area JetGrid is allowed to touch, so the operation was refused.';
    }
}
