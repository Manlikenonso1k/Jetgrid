<?php

namespace App\Console\Commands;

use App\Models\LocalProject;
use App\Services\Local\LocalModeGate;
use App\Services\Local\LocalProjectImporter;
use App\Services\Local\PortResolver;
use App\Services\Local\ProjectScanner;
use App\Services\Local\RunStateProbe;
use Illuminate\Console\Command;

/**
 * L8. Prove the discovery works before any of it reaches the grid.
 *
 * --dry-run is the default posture of this command in spirit: it prints what was
 * found and writes nothing to the database. Without the flag it also imports,
 * which is how the dashboard gets its rows.
 */
class ScanLocalCommand extends Command
{
    protected $signature = 'jetgrid:scan
        {--dry-run : Print what was found and write nothing}
        {--fresh : Ignore the scan cache and re-walk the tree}
        {--no-probe : Skip the run-state probe and only report discovery}';

    protected $description = 'Discover local projects next to JetGrid, resolve their ports and report whether they are running.';

    public function handle(
        LocalModeGate $gate,
        ProjectScanner $scanner,
        PortResolver $ports,
        RunStateProbe $probe,
        LocalProjectImporter $importer,
    ): int {
        $dryRun = (bool) $this->option('dry-run');

        if (! $gate->passes()) {
            $this->components->error('Local mode is off. Nothing was scanned.');

            foreach ($gate->failures() as $failure) {
                $this->line('    <fg=red>x</> '.$failure);
            }

            $this->newLine();
            $this->components->info('Run php artisan jetgrid:doctor for the full gate report.');

            return self::FAILURE;
        }

        $report = $scanner->scan(fresh: (bool) $this->option('fresh'));

        $this->components->info(
            'Scanned '.count($report->roots).' root(s), '.$report->directoriesVisited.' directories'
            .($report->fromCache ? ' (from cache — use --fresh to re-walk)' : '').'.'
        );

        foreach ($report->roots as $root) {
            $this->line('    <fg=gray>root</> '.$root);
        }

        $this->newLine();

        if ($report->projects === []) {
            $this->components->warn('No projects found.');

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($report->projects as $discovered) {
            $override = LocalProject::query()
                ->where('path_key', LocalProject::keyForPath($discovered->path))
                ->value('port_override');

            $resolution = $ports->resolve($discovered->path, $discovered->signature, $override);

            $status = $this->option('no-probe')
                ? null
                : $probe->probe($discovered->path, $resolution->port);

            $rows[] = [
                $discovered->path,
                $discovered->signature->describe(),
                $resolution->port.' <fg=gray>'.$resolution->source.'</>',
                $status === null ? '<fg=gray>not probed</>' : $this->colourise($status->summary()),
                $status === null ? '—' : $status->layer->label(),
            ];
        }

        $this->table(['Path', 'Type', 'Port', 'Running', 'Detected by'], $rows);

        foreach ($report->projects as $discovered) {
            if ($discovered->signature->blockers !== []) {
                $this->line('  <fg=yellow>!</> '.$discovered->signature->name.': '.implode(' ', $discovered->signature->blockers));
            }
        }

        if ($report->errors !== []) {
            $this->newLine();
            $this->components->warn('Unreadable or unresolvable (skipped, not an error):');

            foreach ($report->errors as $error) {
                $this->line('    <fg=gray>?</> '.$error['path'].' — '.$error['reason']);
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->components->info('Dry run: nothing was written to the database, and nothing inside any scanned directory was touched.');

            return self::SUCCESS;
        }

        $imported = $importer->import($report);

        $this->components->info(count($imported).' project(s) imported. Discovery is read-only — no scanned directory was modified.');

        return self::SUCCESS;
    }

    private function colourise(string $summary): string
    {
        return match (true) {
            str_starts_with($summary, 'running, NOT') => '<fg=red>'.$summary.'</>',
            str_contains($summary, 'unattributed') => '<fg=yellow>'.$summary.'</>',
            str_starts_with($summary, 'running') => '<fg=green>'.$summary.'</>',
            default => '<fg=gray>'.$summary.'</>',
        };
    }
}
