<?php

namespace App\Services\Health;

use App\Models\Site;
use App\Services\Privilege\CommandRunner;
use App\Services\Server\ServerDriver;

/**
 * Feature 17 — one-click triage.
 *
 * When a house goes red, this answers the questions you would otherwise SSH in
 * to ask. Every step uses a read-only whitelisted command, so it works while
 * JETGRID_READONLY=true and it is safe to run against an ADOPTED site: reading
 * a protected project's disk usage and error log tells you what is wrong
 * without touching it.
 */
class DiagnosticsService
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ServerDriver $driver,
    ) {
    }

    /** @return list<array{check:string,status:string,detail:string}> */
    public function run(Site $site): array
    {
        return array_values(array_filter([
            $this->diskCheck(),
            $this->loadCheck(),
            $this->httpCheck($site),
            $this->certificateCheck($site),
            $this->documentRootCheck($site),
            $this->errorLogCheck($site),
        ]));
    }

    private function diskCheck(): array
    {
        $outcome = $this->runner->run('disk.usage');

        foreach (preg_split('/\r?\n/', $outcome->result->stdout) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];

            if (count($cols) >= 6 && end($cols) === '/') {
                $used = (int) $cols[2];
                $total = (int) $cols[1];
                $pct = $total > 0 ? round($used / $total * 100) : 0;

                return [
                    'check' => 'Disk space',
                    'status' => $pct >= 95 ? 'fail' : ($pct >= 85 ? 'warn' : 'pass'),
                    'detail' => "Root filesystem {$pct}% full.".($pct >= 95 ? ' A full disk will take every site on this box down.' : ''),
                ];
            }
        }

        return ['check' => 'Disk space', 'status' => 'unknown', 'detail' => 'Could not read df.'];
    }

    private function loadCheck(): array
    {
        $outcome = $this->runner->run('load.avg');
        $parts = preg_split('/\s+/', trim($outcome->result->stdout)) ?: [];
        $load5 = isset($parts[1]) ? (float) $parts[1] : null;

        if ($load5 === null) {
            return ['check' => 'Load average', 'status' => 'unknown', 'detail' => 'Could not read /proc/loadavg.'];
        }

        return [
            'check' => 'Load average',
            'status' => $load5 > 4 ? 'fail' : ($load5 > 2 ? 'warn' : 'pass'),
            'detail' => "5-minute load average {$load5}.",
        ];
    }

    private function httpCheck(Site $site): array
    {
        $start = microtime(true);
        $context = stream_context_create([
            'http' => ['method' => 'HEAD', 'timeout' => 8, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $headers = @get_headers("https://{$site->domain}/", context: $context);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($headers === false) {
            return [
                'check' => 'HTTP response',
                'status' => 'fail',
                'detail' => "No response from https://{$site->domain}/ — DNS, firewall, TLS or the web server itself.",
            ];
        }

        $status = (int) (preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $m) === 1 ? $m[1] : 0);

        return [
            'check' => 'HTTP response',
            'status' => $status >= 500 ? 'fail' : ($status >= 400 ? 'warn' : 'pass'),
            'detail' => "HTTP {$status} in {$ms}ms.",
        ];
    }

    private function certificateCheck(Site $site): array
    {
        $certificate = $site->certificate;

        if ($certificate === null) {
            return ['check' => 'Certificate', 'status' => 'warn', 'detail' => 'No certificate recorded for this site.'];
        }

        $days = $certificate->daysRemaining();

        return [
            'check' => 'Certificate',
            'status' => match (true) {
                $days === null => 'unknown',
                $days <= 0 => 'fail',
                $days <= 14 => 'warn',
                default => 'pass',
            },
            'detail' => $days === null
                ? 'Expiry unknown.'
                : ($days <= 0 ? "EXPIRED {$days} days ago." : "Valid for {$days} more days."),
        ];
    }

    private function documentRootCheck(Site $site): ?array
    {
        if (! $site->document_root) {
            return null;
        }

        $exists = $this->driver->fileExists($site->document_root);

        return [
            'check' => 'Document root',
            'status' => $exists ? 'pass' : 'fail',
            'detail' => $exists
                ? "{$site->document_root} present."
                : "{$site->document_root} does not exist — a failed deploy or a moved release directory.",
        ];
    }

    private function errorLogCheck(Site $site): ?array
    {
        $candidates = array_filter([
            "/var/log/nginx/{$site->domain}.error.log",
            $site->document_root ? dirname($site->document_root).'/storage/logs/laravel.log' : null,
        ]);

        foreach ($candidates as $path) {
            $contents = $this->driver->readFile($path);

            if ($contents === null) {
                continue;
            }

            $lines = array_slice(array_filter(preg_split('/\r?\n/', $contents) ?: []), -5);

            return [
                'check' => 'Recent errors',
                'status' => $lines === [] ? 'pass' : 'warn',
                'detail' => $lines === []
                    ? "{$path} is empty."
                    : "Last lines of {$path}:\n".implode("\n", $lines),
            ];
        }

        return ['check' => 'Recent errors', 'status' => 'unknown', 'detail' => 'No readable error log found for this site.'];
    }
}
