<?php

namespace App\Services\Privilege;

use App\Models\AuditLog;
use App\Services\Server\ProcessResult;

final class CommandOutcome
{
    public function __construct(
        public readonly BoundCommand $command,
        public readonly ProcessResult $result,
        public readonly bool $wasDryRun,
        public readonly AuditLog $audit,
    ) {}

    public function ok(): bool
    {
        return $this->result->ok();
    }

    /** The literal line that ran, or would have run. */
    public function preview(): string
    {
        return $this->command->display();
    }
}
