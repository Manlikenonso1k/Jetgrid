<?php

namespace App\Services\Certificates;

final class PreflightCheck
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
    ) {
    }

    public function blocking(): bool
    {
        return $this->status === self::FAIL;
    }
}
