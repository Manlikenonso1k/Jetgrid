<?php

namespace App\Services\Local;

use App\Models\LocalProject;
use App\Support\CanonicalPath;

/**
 * Turns a ScanReport into rows, incrementally.
 *
 * "Incrementally" means two things, both required by L2: the walk itself is
 * cached in ProjectScanner so a page load does not re-read the tree, and this
 * class updates existing rows in place rather than truncating and re-inserting.
 * The second matters more than it looks — a port override, an auto-port choice
 * and a project's grid coordinates all live on the row, and a rescan that
 * replaced rows would silently discard the operator's decisions and make every
 * house jump to a new square.
 */
class LocalProjectImporter
{
    public function __construct(
        private readonly PortResolver $ports,
        private readonly RepoInspector $repo,
    ) {}

    /**
     * @param  bool  $withRepoInfo  git costs two processes per project; the scan
     *                              command wants it, a poll does not
     * @return list<LocalProject>
     */
    public function import(ScanReport $report, bool $withRepoInfo = true): array
    {
        $imported = [];

        foreach ($report->projects as $project) {
            $imported[] = $this->one($project, $withRepoInfo);
        }

        return $imported;
    }

    public function one(DiscoveredProject $discovered, bool $withRepoInfo = true): LocalProject
    {
        $key = LocalProject::keyForPath($discovered->path);
        $signature = $discovered->signature;

        $record = LocalProject::firstOrNew(['path_key' => $key]);

        $resolution = $this->ports->resolve($discovered->path, $signature, $record->port_override);
        $repo = $withRepoInfo ? $this->repo->inspect($discovered->path) : ['branch' => $record->git_branch, 'dirty' => $record->git_dirty];

        $record->forceFill([
            // Observation. Every one of these is replaced on each scan, because
            // the filesystem is the source of truth for all of them.
            'path' => CanonicalPath::normalise($discovered->path),
            'path_key' => $key,
            'name' => $signature->name,
            'declared_name' => $signature->declaredName,
            'scan_root' => $discovered->root,
            'type' => $signature->type,
            'type_marker' => $signature->marker,
            'framework' => $signature->framework,
            'framework_version' => $signature->version,
            'has_docker' => $signature->hasDocker,
            'install_state' => $signature->installState,
            'blockers' => $signature->blockers,
            'package_manager' => $signature->packageManager,
            'dev_script' => $signature->devScript,
            'port' => $resolution->port,
            'port_source' => $resolution->describe(),
            'git_branch' => $repo['branch'],
            'git_dirty' => $repo['dirty'],
            'source_modified_at' => $discovered->modifiedAt !== null ? now()->setTimestamp($discovered->modifiedAt) : null,
            'last_seen_at' => now(),
        ]);

        // port_override, auto_port, enabled and the grid coordinates are never
        // touched here: they are the operator's, not the scan's.
        $record->save();

        return $record;
    }
}
