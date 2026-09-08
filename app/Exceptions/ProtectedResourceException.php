<?php

namespace App\Exceptions;

/**
 * Safety constraint #1. Raised when anything attempts to mutate an adopted
 * resource. This is a hard gate, NOT a permission: god_mode hits it too.
 */
class ProtectedResourceException extends JetGridSafetyException
{
    public function __construct(public readonly string $resource, string $attempted = 'write')
    {
        parent::__construct("Refused to {$attempted} protected resource [{$resource}]. Adopted resources are monitoring-only.");
    }

    public function userMessage(): string
    {
        return "“{$this->resource}” is an adopted, protected resource. JetGrid did not create it and will never modify it. This gate cannot be lifted by any role, including god mode.";
    }
}
