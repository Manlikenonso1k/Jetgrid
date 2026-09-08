<?php

namespace App\Services\Server;

use App\Services\Privilege\BoundCommand;
use Symfony\Component\Process\Process;

/**
 * Talks to a real Linux host.
 *
 * Note the constructor of Process: it takes the argv ARRAY. Nothing here is ever
 * handed to a shell, so a hostile value in an argument cannot become a second
 * command — it can only ever be one (rejected) argument.
 */
class LinuxServerDriver implements ServerDriver
{
    public function name(): string
    {
        return 'linux';
    }

    public function isFake(): bool
    {
        return false;
    }

    public function run(BoundCommand $command, int $timeoutSeconds = 60): ProcessResult
    {
        $started = microtime(true);

        $process = new Process($command->argv, timeout: $timeoutSeconds);
        $process->run();

        return new ProcessResult(
            exitCode: $process->getExitCode() ?? -1,
            stdout: $process->getOutput(),
            stderr: $process->getErrorOutput(),
            durationMs: (int) round((microtime(true) - $started) * 1000),
        );
    }

    public function readFile(string $path): ?string
    {
        if (! is_readable($path) || ! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public function listDirectory(string $path): array
    {
        if (! is_dir($path) || ! is_readable($path)) {
            return [];
        }

        $entries = @scandir($path);

        if ($entries === false) {
            return [];
        }

        return array_values(array_map(
            static fn (string $e): string => rtrim($path, '/').'/'.$e,
            array_filter($entries, static fn (string $e): bool => $e !== '.' && $e !== '..')
        ));
    }

    public function fileExists(string $path): bool
    {
        return file_exists($path);
    }

    public function modifiedAt(string $path): ?int
    {
        $t = @filemtime($path);

        return $t === false ? null : $t;
    }
}
