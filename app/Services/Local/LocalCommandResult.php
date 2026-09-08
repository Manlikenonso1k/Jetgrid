<?php

namespace App\Services\Local;

use App\Models\AuditLog;
use App\Services\Privilege\BoundCommand;
use App\Services\Server\ProcessResult;

final class LocalCommandResult
{
    public function __construct(
        public readonly BoundCommand $command,
        public readonly ProcessResult $result,
        public readonly bool $available,
        public readonly ?AuditLog $audit = null,
    ) {}

    /** False either because the binary is absent or because the command failed. */
    public function ok(): bool
    {
        return $this->available && $this->result->ok();
    }

    public function stdout(): string
    {
        return $this->result->stdout;
    }

    public function preview(): string
    {
        return $this->command->display();
    }
}
