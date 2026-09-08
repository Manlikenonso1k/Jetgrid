<?php

namespace App\Services\Local\Platform;

/**
 * lsof's listener table, parsed once for the two platforms that use it.
 *
 * Shared rather than duplicated because the format is lsof's, not the operating
 * system's — the same binary produces the same table on macOS and on a Linux box
 * with no iproute2.
 */
final class LsofOutput
{
    /**
     * The PID of the first listening socket in the output.
     *
     * The LISTEN marker is required rather than assumed. lsof is invoked with
     * -sTCP:LISTEN so every row should be a listener, but a row that is not one
     * must not be read as though it were: attributing a port to the wrong PID is
     * how a tool ends up killing something it did not start.
     */
    public static function firstListenerPid(string $stdout): ?int
    {
        return self::listenerPids($stdout)[0] ?? null;
    }

    /**
     * Every listening PID in the output.
     *
     * lsof routinely reports the same port twice — once for the IPv4 socket and
     * once for IPv6 — and a port can genuinely be held by more than one process.
     * Attribution needs all of them, because testing only the first makes the
     * answer depend on lsof's row order.
     *
     * @return list<int>
     */
    public static function listenerPids(string $stdout): array
    {
        $pids = [];

        foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];

            if (count($fields) < 9 || $fields[0] === 'COMMAND') {
                continue;
            }

            if (! str_contains($line, 'LISTEN')) {
                continue;
            }

            if (ctype_digit($fields[1])) {
                $pids[] = (int) $fields[1];
            }
        }

        return array_values(array_unique($pids));
    }
}
