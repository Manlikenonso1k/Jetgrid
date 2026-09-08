<?php

namespace App\Services\Local;

use App\Enums\DetectionLayer;
use App\Enums\RunState;

/**
 * What the layered probe concluded, and which layer concluded it.
 *
 * `attributed` is a tri-state on purpose. True means a process was matched to
 * this project's directory; false means a process was found and it belongs to
 * something else; null means attribution was not available at all — a missing
 * lsof, a socket owned by another user. L5 requires those three to stay
 * distinguishable rather than collapsing into a guess.
 */
final class RunStatus
{
    public function __construct(
        public readonly RunState $state,
        public readonly DetectionLayer $layer,
        public readonly int $port,
        public readonly ?bool $attributed = null,
        public readonly ?int $pid = null,
        public readonly ?string $processName = null,
        public readonly ?string $commandLine = null,
        public readonly ?string $workingDirectory = null,
        public readonly ?int $httpStatus = null,
        public readonly ?int $responseMs = null,
        public readonly ?string $container = null,
        public readonly ?string $note = null,
    ) {}

    public function isRunning(): bool
    {
        return $this->state->isUp();
    }

    /** The one-line answer `jetgrid:scan` prints. */
    public function summary(): string
    {
        return match (true) {
            $this->state === RunState::Docker => 'running (docker: '.($this->container ?? 'unknown container').')',
            $this->attributed === false => 'running, NOT this project',
            $this->attributed === null && $this->state->isUp() => 'running, unattributed',
            default => $this->state->label(),
        };
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'layer' => $this->layer->value,
            'port' => $this->port,
            'attributed' => $this->attributed,
            'pid' => $this->pid,
            'process_name' => $this->processName,
            'command_line' => $this->commandLine,
            'working_directory' => $this->workingDirectory,
            'http_status' => $this->httpStatus,
            'response_ms' => $this->responseMs,
            'container' => $this->container,
            'note' => $this->note,
        ];
    }
}
