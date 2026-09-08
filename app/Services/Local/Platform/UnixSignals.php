<?php

namespace App\Services\Local\Platform;

/**
 * posix_kill, when the extension is there.
 *
 * Signalling in-process is preferable to spawning `kill`: it is faster, it
 * cannot be affected by PATH, and it reports failure as a return value rather
 * than as an exit code that has to be interpreted. When ext-posix is missing —
 * a normal state on a minimal PHP build — the caller falls back to the
 * whitelisted `unix.process.term` / `unix.process.kill` commands.
 */
final class UnixSignals
{
    public static function available(): bool
    {
        return function_exists('posix_kill');
    }

    public static function send(int $pid, bool $force): bool
    {
        if (! self::available()) {
            return false;
        }

        // 15/9 as literals rather than SIGTERM/SIGKILL: those constants come
        // from ext-pcntl, which is a different extension and may not be loaded
        // even when ext-posix is.
        return posix_kill($pid, $force ? 9 : 15);
    }

    /** Signal 0 tests for existence without delivering anything. */
    public static function alive(int $pid): ?bool
    {
        if (! self::available()) {
            return null;
        }

        return posix_kill($pid, 0);
    }
}
