<?php

namespace App\Services\Privilege;

/** A whitelisted command with validated arguments substituted in. */
final class BoundCommand
{
    /**
     * @param  list<string>  $argv
     * @param  array<string,string|int>  $args
     */
    public function __construct(
        public readonly PrivilegedCommand $definition,
        public readonly array $argv,
        public readonly array $args,
    ) {}

    /**
     * Human-readable rendering, shown in dry-run previews and the audit log.
     * This is for DISPLAY ONLY — execution always uses the argv array.
     */
    public function display(): string
    {
        return implode(' ', array_map(static function (string $token): string {
            return preg_match('/^[A-Za-z0-9_@%+=:,.\/-]+$/', $token) === 1
                ? $token
                : "'".str_replace("'", "'\''", $token)."'";
        }, $this->argv));
    }
}
