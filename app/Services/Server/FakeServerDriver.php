<?php

namespace App\Services\Server;

use App\Services\Privilege\BoundCommand;

/**
 * A host made of files on disk.
 *
 * The fixtures directory mirrors a small Linux box:
 *   fixtures/fs/etc/nginx/sites-enabled/...   — read by discovery
 *   fixtures/commands/<command.key>.txt       — stdout returned by run()
 *
 * run() never spawns a process. That is the point: the whole UI, discovery
 * pipeline and 3D dashboard can be developed and tested on a machine that has
 * no nginx, no systemd and no certbot — including Windows — with zero risk of
 * reaching a real server. It is also what the deliverable Docker Compose stack
 * uses when you would rather not point dev at a live box.
 */
class FakeServerDriver implements ServerDriver
{
    public function __construct(private readonly string $fixturesPath)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isFake(): bool
    {
        return true;
    }

    public function run(BoundCommand $command, int $timeoutSeconds = 60): ProcessResult
    {
        $file = $this->fixturesPath.'/commands/'.$command->definition->key.'.txt';

        if (! is_file($file)) {
            return new ProcessResult(
                exitCode: 0,
                stdout: '',
                stderr: "[fake driver] no fixture for {$command->definition->key}",
                durationMs: 0,
            );
        }

        $stdout = (string) file_get_contents($file);

        // Fixtures may reference their bound arguments, so one fixture can serve
        // many domains: {domain} in the file is replaced with the bound value.
        foreach ($command->args as $name => $value) {
            $stdout = str_replace('{'.$name.'}', (string) $value, $stdout);
        }

        return new ProcessResult(0, $stdout, '', 3);
    }

    public function readFile(string $path): ?string
    {
        $real = $this->translate($path);

        if ($real === null || ! is_file($real)) {
            return null;
        }

        $contents = @file_get_contents($real);

        return $contents === false ? null : $contents;
    }

    public function listDirectory(string $path): array
    {
        $real = $this->translate($path);

        if ($real === null || ! is_dir($real)) {
            return [];
        }

        $entries = @scandir($real) ?: [];

        return array_values(array_map(
            static fn (string $e): string => rtrim($path, '/').'/'.$e,
            array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..')
        ));
    }

    public function fileExists(string $path): bool
    {
        $real = $this->translate($path);

        return $real !== null && file_exists($real);
    }

    public function modifiedAt(string $path): ?int
    {
        $real = $this->translate($path);

        if ($real === null) {
            return null;
        }

        $t = @filemtime($real);

        return $t === false ? null : $t;
    }

    /**
     * Map an absolute Linux path onto the fixture tree, refusing anything that
     * would climb out of it. Windows paths use the same forward-slash form here
     * because every path in this app originates as a Linux path.
     */
    private function translate(string $path): ?string
    {
        $normalised = '/'.ltrim(str_replace('\\', '/', $path), '/');

        if (str_contains($normalised, '/../')) {
            return null;
        }

        return rtrim($this->fixturesPath, '/\\').'/fs'.$normalised;
    }
}
