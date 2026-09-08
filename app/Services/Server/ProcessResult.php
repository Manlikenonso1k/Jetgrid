<?php

namespace App\Services\Server;

final class ProcessResult
{
    public function __construct(
        public readonly int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly int $durationMs,
        public readonly bool $skipped = false,
    ) {}

    public function ok(): bool
    {
        return $this->exitCode === 0;
    }

    /** A dry run produced no process at all. */
    public static function skipped(string $reason): self
    {
        return new self(0, '', $reason, 0, true);
    }
}
