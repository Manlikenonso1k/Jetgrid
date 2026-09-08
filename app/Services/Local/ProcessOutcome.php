<?php

namespace App\Services\Local;

/**
 * The result of a start, stop or restart.
 *
 * `command` is always populated, including on a refusal: L6 requires a refusal
 * to say what would have run and what is in the way, rather than failing with an
 * opaque error.
 */
final class ProcessOutcome
{
    /** @param array<string,mixed> $context */
    private function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly ?string $command = null,
        public readonly ?int $pid = null,
        public readonly ?int $port = null,
        public readonly array $context = [],
    ) {}

    /** @param array<string,mixed> $context */
    public static function success(string $message, ?string $command = null, ?int $pid = null, ?int $port = null, array $context = []): self
    {
        return new self(true, $message, $command, $pid, $port, $context);
    }

    /** @param array<string,mixed> $context */
    public static function refused(string $message, ?string $command = null, array $context = []): self
    {
        return new self(false, $message, $command, null, null, $context);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'ok' => $this->ok,
            'message' => $this->message,
            'command' => $this->command,
            'pid' => $this->pid,
            'port' => $this->port,
            'context' => $this->context,
        ];
    }
}
