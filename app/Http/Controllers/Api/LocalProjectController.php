<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LocalProject;
use App\Services\Local\LocalProjectImporter;
use App\Services\Local\ProcessController;
use App\Services\Local\ProcessOutcome;
use App\Services\Local\ProjectScanner;
use App\Services\Local\RunStateProbe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The local district's data and controls.
 *
 * Every route that reaches this controller is behind EnsureLocalMode — see
 * routes/web.php and the test that asserts it — so nothing here re-checks the
 * gate for HTTP callers. The service layer checks it again anyway, because the
 * artisan commands reach the same services without passing through middleware.
 */
class LocalProjectController extends Controller
{
    public function index(RunStateProbe $probe): JsonResponse
    {
        $projects = LocalProject::query()->enabled()->orderBy('name')->get();

        $payload = $projects->map(function (LocalProject $project) use ($probe): array {
            $port = $project->effectivePort();

            $status = $port === null
                ? null
                : $probe->probe($project->path, $port, $project->pid, $project->started_at?->getTimestamp());

            if ($status !== null) {
                $project->applyRunStatus($status);
            }

            return $this->present($project, $status?->toArray());
        });

        return response()->json([
            // The dashboard polls; it does not get pushed to. L5 is explicit
            // that this is a UI concern and must stop when the tab is closed.
            'poll_ms' => (int) config('jetgrid.local.probe.poll_ms', 5000),
            'projects' => $payload,
        ]);
    }

    public function show(LocalProject $project, ProcessController $processes, RunStateProbe $probe): JsonResponse
    {
        $port = $project->effectivePort();

        $status = $port === null
            ? null
            : $probe->probe($project->path, $port, $project->pid, $project->started_at?->getTimestamp());

        if ($status !== null) {
            $project->applyRunStatus($status);
        }

        return response()->json($this->present($project, $status?->toArray()) + [
            'start_command' => $processes->previewStart($project),
            'log' => $processes->tail($project),
        ]);
    }

    public function log(LocalProject $project, ProcessController $processes, Request $request): JsonResponse
    {
        return response()->json([
            'path' => $project->logPath(),
            'lines' => $processes->tail($project, (int) $request->integer('lines', 200)),
        ]);
    }

    public function rescan(ProjectScanner $scanner, LocalProjectImporter $importer): JsonResponse
    {
        $report = $scanner->scan(fresh: true);
        $importer->import($report);

        return response()->json([
            'scanned_roots' => $report->roots,
            'found' => $report->total(),
            'directories_visited' => $report->directoriesVisited,
            // L1: unreadable directories are surfaced, not swallowed.
            'errors' => $report->errors,
        ]);
    }

    public function start(LocalProject $project, ProcessController $processes): JsonResponse
    {
        return $this->outcome($processes->start($project));
    }

    public function stop(LocalProject $project, ProcessController $processes): JsonResponse
    {
        return $this->outcome($processes->stop($project));
    }

    public function restart(LocalProject $project, ProcessController $processes): JsonResponse
    {
        return $this->outcome($processes->restart($project));
    }

    /**
     * L6 hard rule. `confirm` must be the literal command line, which the client
     * gets from `start_command`/the preview endpoint — a boolean would make the
     * confirmation a formality.
     */
    public function maintenance(LocalProject $project, Request $request, ProcessController $processes): JsonResponse
    {
        $validated = $request->validate([
            'action' => ['required', 'string'],
            'confirm' => ['required', 'string'],
        ]);

        return $this->outcome($processes->maintenance($project, $validated['action'], $validated['confirm']));
    }

    private function outcome(ProcessOutcome $outcome): JsonResponse
    {
        return response()->json($outcome->toArray(), $outcome->ok ? 200 : 409);
    }

    /** @param array<string,mixed>|null $status */
    private function present(LocalProject $project, ?array $status): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'path' => $project->path,
            'type' => $project->type->value,
            'type_label' => $project->type->label(),
            'type_marker' => $project->type_marker,
            'framework' => $project->framework,
            'framework_version' => $project->framework_version,
            'has_docker' => $project->has_docker,
            'install_state' => $project->install_state->value,
            'blockers' => $project->blockers ?? [],
            'port' => $project->effectivePort(),
            'port_source' => $project->port_source,
            'url' => $project->url(),
            'git_branch' => $project->git_branch,
            'git_dirty' => $project->git_dirty,
            'beacon' => $project->beacon()->value,
            'district' => 'local',
            'status' => $status,
        ];
    }
}
