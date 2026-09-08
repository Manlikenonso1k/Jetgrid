<?php

namespace App\Console\Commands;

use App\Services\Local\LocalModeGate;
use App\Services\Local\Platform\PlatformDetector;
use App\Services\Local\Platform\ToolLocator;
use App\Services\Local\ProjectScanner;
use Illuminate\Console\Command;

/**
 * L8. What was detected, what is installed, and what stops working without it.
 *
 * The point of the third column is that a missing tool should never be a
 * mystery. Docker is absent on most workstations and that is fine; the operator
 * needs to be told that it means the Docker layer is skipped, not that something
 * is broken.
 */
class DoctorCommand extends Command
{
    protected $signature = 'jetgrid:doctor';

    protected $description = 'Report the detected platform, which local-mode tools are available, and what degrades without them.';

    /** Tools every platform can use, beyond the platform-specific set. */
    private const SHARED_TOOLS = [
        'docker' => 'The Docker layer (L5 layer 4) and compose start/stop. Without it, compose projects are detected from their compose file but never reported as running under Docker.',
        'git' => 'Branch and dirty state in the project panel. Without it, repo information is simply absent.',
        'code' => 'The "Open in Editor" action. Without it, the action is not offered.',
        'php' => 'Starting Laravel, WordPress, generic PHP and static projects.',
        'npm' => 'Starting Node projects and the npm install action.',
        'python' => 'Starting Django projects.',
        'go' => 'Starting Go projects.',
        'composer' => 'The composer install action.',
    ];

    public function handle(
        PlatformDetector $platform,
        ToolLocator $tools,
        LocalModeGate $gate,
        ProjectScanner $scanner,
    ): int {
        $this->components->info('JetGrid local mode — platform report');

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Platform</>', '<fg=gray>Value</>');
        $this->components->twoColumnDetail('Detected family', $platform->family()->label());
        $this->components->twoColumnDetail('PHP_OS_FAMILY', PHP_OS_FAMILY);
        $this->components->twoColumnDetail('PHP version', PHP_VERSION);
        $this->components->twoColumnDetail('Directory separator', DIRECTORY_SEPARATOR);
        $this->components->twoColumnDetail('posix_kill available', $this->yesNo(function_exists('posix_kill')));

        // ---- L0 gate ---------------------------------------------------------

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Local-mode gate (L0)</>', '<fg=gray>Result</>');

        foreach ($gate->report() as $check => $passed) {
            $this->components->twoColumnDetail(
                $check,
                $passed ? '<fg=green>pass</>' : '<fg=red>FAIL</>',
            );
        }

        $marker = $gate->productionMarker();

        if ($marker !== null) {
            $this->line('    <fg=red>x</> Production marker present: '.$marker);
        }

        $this->newLine();
        $this->components->twoColumnDetail(
            'Local mode',
            $gate->passes() ? '<fg=green>ENABLED</>' : '<fg=red>DISABLED</>',
        );

        // ---- Workstation evidence -------------------------------------------

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Headless-server evidence</>', '<fg=gray>Verdict</>');

        foreach ($platform->workstationEvidence() as $signal) {
            $this->components->twoColumnDetail(
                $signal['signal'],
                $signal['server'] ? '<fg=red>server</>' : '<fg=green>not a server</>',
            );
            $this->line('    <fg=gray>'.$signal['detail'].'</>');
        }

        // ---- Tools -----------------------------------------------------------

        $matrix = $platform->commands()->toolMatrix() + self::SHARED_TOOLS;
        ksort($matrix);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Tool</>', '<fg=gray>Status</>');

        $missing = [];

        foreach ($matrix as $binary => $degradation) {
            $path = $tools->path($binary);

            $this->components->twoColumnDetail(
                $binary,
                $path === null ? '<fg=yellow>missing</>' : '<fg=green>'.$path.'</>',
            );

            if ($path === null) {
                $missing[$binary] = $degradation;
            }
        }

        $this->newLine();

        if ($missing === []) {
            $this->components->info('Every tool local mode knows how to use is installed.');
        } else {
            $this->components->warn(count($missing).' tool(s) missing. What degrades:');

            foreach ($missing as $binary => $degradation) {
                $this->line('    <fg=yellow>-</> <options=bold>'.$binary.'</> — '.$degradation);
            }

            $this->newLine();
            $this->components->info('None of the above is fatal. Layers 1 and 2 of the run-state probe are pure PHP and always work.');
        }

        // ---- Scan roots ------------------------------------------------------

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Scan roots (L2)</>', '<fg=gray>Depth '.config('jetgrid.local.scan.max_depth').'</>');

        foreach ($scanner->roots() as $root) {
            $this->components->twoColumnDetail($root, is_readable($root) ? '<fg=green>readable</>' : '<fg=red>unreadable</>');
        }

        $this->components->twoColumnDetail('Excluded (itself)', base_path());

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>JetGrid state</>', '<fg=gray>Path</>');
        $this->components->twoColumnDetail('PID files', (string) config('jetgrid.local.pid_dir'));
        $this->components->twoColumnDetail('Project logs', (string) config('jetgrid.local.log_dir'));

        $this->newLine();
        $this->components->info('Every command local mode can run is listed in docs/LOCAL-MODE-COMMANDS.md (php artisan jetgrid:local-commands --markdown).');

        return $gate->passes() ? self::SUCCESS : self::FAILURE;
    }

    private function yesNo(bool $value): string
    {
        return $value ? '<fg=green>yes</>' : '<fg=yellow>no</>';
    }
}
