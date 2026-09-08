<?php

namespace App\Services\Local;

use App\Models\AuditLog;
use App\Services\Privilege\BoundCommand;

/** The outcome of a detached spawn. A PID means the process exists, not that it works. */
final class SpawnResult
{
    public function __construct(
        public readonly BoundCommand $command,
        public readonly ?int $pid,
        public readonly ?string $error,
        public readonly string $logPath,
        public readonly ?AuditLog $audit = null,
    ) {}

    public function ok(): bool
    {
        return $this->pid !== null;
    }
}
