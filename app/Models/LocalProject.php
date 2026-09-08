<?php

namespace App\Models;

use App\Enums\BeaconColor;
use App\Enums\DetectionLayer;
use App\Enums\InstallState;
use App\Enums\LocalProjectType;
use App\Enums\RunState;
use App\Services\Local\RunStatus;
use App\Support\CanonicalPath;
use Illuminate\Database\Eloquent\Model;

/**
 * A project folder found on this machine.
 *
 * The record is JetGrid's, not the project's: nothing on this model ever results
 * in a write inside the discovered directory. `port_override`, `enabled` and
 * `auto_port` are the only columns a human sets; everything else is JetGrid's
 * own observation and is replaced on the next scan.
 */
class LocalProject extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => LocalProjectType::class,
            'install_state' => InstallState::class,
            'run_state' => RunState::class,
            'detection_layer' => DetectionLayer::class,
            'blockers' => 'array',
            'meta' => 'array',
            'has_docker' => 'boolean',
            'attributed' => 'boolean',
            'auto_port' => 'boolean',
            'enabled' => 'boolean',
            'git_dirty' => 'boolean',
            'started_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'probed_at' => 'datetime',
            'source_modified_at' => 'datetime',
        ];
    }

    /**
     * The identity of a project is its canonical path, hashed so it fits an
     * index on every database JetGrid supports — a Windows path plus a deep
     * folder name overruns MySQL's key length long before it overruns the
     * column.
     */
    public static function keyForPath(string $path): string
    {
        return hash('sha256', CanonicalPath::comparable($path));
    }

    public function scopeEnabled($query)
    {
        return $query->where('enabled', true);
    }

    /** The port the project will actually be started on. */
    public function effectivePort(): ?int
    {
        return $this->port_override ?? $this->port;
    }

    public function url(): ?string
    {
        $port = $this->effectivePort();

        return $port === null ? null : 'http://127.0.0.1:'.$port;
    }

    /** Where this project's stdout and stderr go. Always inside JetGrid's storage. */
    public function logPath(): string
    {
        return rtrim((string) config('jetgrid.local.log_dir'), '\\/')
            .DIRECTORY_SEPARATOR.$this->slug().'.log';
    }

    public function pidPath(): string
    {
        return rtrim((string) config('jetgrid.local.pid_dir'), '\\/')
            .DIRECTORY_SEPARATOR.$this->slug().'.pid';
    }

    /**
     * A filename-safe identifier. The path hash is appended because two folders
     * in different roots can legitimately share a name, and their logs must not
     * be the same file.
     */
    public function slug(): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $this->name) ?: 'project';

        return trim($name, '-').'-'.substr($this->path_key, 0, 8);
    }

    /**
     * The beacon this project shows on the grid.
     *
     * Install state outranks run state: a project with no vendor/ that somehow
     * has something on its port is still the project you cannot start, and the
     * yellow beacon is what says so.
     */
    public function beacon(): BeaconColor
    {
        if ($this->install_state !== InstallState::Ready && ! $this->run_state->isUp()) {
            return BeaconColor::Yellow;
        }

        return $this->run_state->beacon();
    }

    /** Apply a probe result. Observation only — never touches user-set columns. */
    public function applyRunStatus(RunStatus $status): void
    {
        $this->forceFill([
            'run_state' => $status->state,
            'detection_layer' => $status->layer,
            'attributed' => $status->attributed,
            'pid' => $status->pid ?? $this->pid,
            'process_name' => $status->processName,
            'http_status' => $status->httpStatus,
            'response_ms' => $status->responseMs,
            'probed_at' => now(),
        ])->save();
    }
}
