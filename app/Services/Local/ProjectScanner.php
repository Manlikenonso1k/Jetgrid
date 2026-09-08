<?php

namespace App\Services\Local;

use App\Support\CanonicalPath;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * L2. Walks the scan roots and returns the directories that look like projects.
 *
 * READ ONLY. This class opens files to read manifests and never writes one. All
 * state produced by a scan is written to JetGrid's own database and cache, never
 * back into a discovered directory.
 *
 * The walk is driven here rather than handed wholesale to Finder's recursive
 * mode, because two things have to happen that a recursive Finder cannot do:
 *
 *   - PRUNE. Once a directory is identified as a project, its subtree is not
 *     descended. Without that, a Laravel app contributes several hundred
 *     directories to the walk and every one of them gets a manifest check.
 *   - REPORT. An unreadable directory has to end up in $errors with its reason,
 *     not vanish. Finder's ignoreUnreadableDirs() silently drops them, which is
 *     the opposite of what L1 asks for.
 *
 * So Finder is used for what it is good at — listing one directory's children
 * safely, with the platform differences already handled — and the traversal
 * order, depth limit and loop detection live here.
 */
class ProjectScanner
{
    public function __construct(private readonly ProjectTypeDetector $detector) {}

    /**
     * @param  bool  $fresh  bypass the cache; what the "Rescan" action calls
     */
    public function scan(bool $fresh = false): ScanReport
    {
        $key = $this->cacheKey();
        $ttl = (int) config('jetgrid.local.scan.cache_ttl', 300);

        if (! $fresh && $ttl > 0) {
            $cached = Cache::get($key);

            if ($cached instanceof ScanReport) {
                return new ScanReport(
                    $cached->projects,
                    $cached->errors,
                    $cached->roots,
                    $cached->directoriesVisited,
                    fromCache: true,
                );
            }
        }

        $report = $this->walk();

        if ($ttl > 0) {
            Cache::put($key, $report, $ttl);
        }

        return $report;
    }

    /**
     * The roots to walk, canonicalised and de-duplicated.
     *
     * The first is derived, not configured: L2 says the default root is the
     * PARENT of JetGrid's own base_path, so JetGrid installed at
     * Documents/jetgrid scans Documents. Configuring it would let a fat finger
     * point the scan at C:\.
     *
     * @return list<string>
     */
    public function roots(): array
    {
        $roots = [dirname(base_path())];

        foreach ((array) config('jetgrid.local.scan.extra_roots', []) as $extra) {
            $roots[] = trim((string) $extra);
        }

        $canonical = [];

        foreach ($roots as $root) {
            if ($root === '') {
                continue;
            }

            $real = CanonicalPath::of($root);

            if ($real === null || ! is_dir($real)) {
                continue;
            }

            $canonical[CanonicalPath::comparable($real)] = $real;
        }

        return array_values($canonical);
    }

    private function walk(): ScanReport
    {
        $maxDepth = max(1, (int) config('jetgrid.local.scan.max_depth', 2));
        $skip = (array) config('jetgrid.local.scan.skip', []);
        $self = CanonicalPath::of(base_path()) ?? base_path();

        $projects = [];
        $errors = [];
        $visited = [];
        $roots = $this->roots();
        $visits = 0;

        foreach ($roots as $root) {
            // Breadth-first, so the shallowest spelling of a project wins if the
            // same tree is reachable by two paths.
            $queue = [[$root, 0]];

            while ($queue !== []) {
                [$directory, $depth] = array_shift($queue);
                $visits++;

                foreach ($this->children($directory, $errors) as $child) {
                    $canonical = CanonicalPath::of($child);

                    if ($canonical === null) {
                        // A junction whose target is gone, or a path the process
                        // may not resolve. Neither is worth aborting for.
                        $errors[] = ['path' => $child, 'reason' => 'Could not be resolved to a real path.'];

                        continue;
                    }

                    $key = CanonicalPath::comparable($canonical);

                    // Loop guard. On Windows, Documents contains junctions to
                    // Music and Videos; on Unix a symlink to an ancestor does
                    // the same job. Either way the tree is finite only if a
                    // directory is entered once.
                    if (isset($visited[$key])) {
                        continue;
                    }

                    $visited[$key] = true;

                    if ($this->isSkipped($canonical, $skip)) {
                        continue;
                    }

                    // L2: JetGrid never appears in its own results.
                    if (CanonicalPath::same($canonical, $self)) {
                        continue;
                    }

                    $signature = $this->detect($canonical, $errors);

                    if ($signature !== null) {
                        $projects[] = new DiscoveredProject(
                            path: $canonical,
                            root: $root,
                            depth: $depth + 1,
                            signature: $signature,
                            modifiedAt: @filemtime($canonical) ?: null,
                        );

                        // Prune: the inside of a project is the project's
                        // business, not the grid's.
                        continue;
                    }

                    if ($depth + 1 < $maxDepth) {
                        $queue[] = [$canonical, $depth + 1];
                    }
                }
            }
        }

        usort($projects, static fn (DiscoveredProject $a, DiscoveredProject $b): int => strcasecmp($a->signature->name, $b->signature->name));

        return new ScanReport($projects, $errors, $roots, $visits);
    }

    /**
     * One directory's immediate subdirectories.
     *
     * @param  list<array{path:string,reason:string}>  $errors
     * @return list<string>
     */
    private function children(string $directory, array &$errors): array
    {
        try {
            $finder = (new Finder)
                ->directories()
                ->depth(0)
                ->ignoreUnreadableDirs()
                ->ignoreDotFiles(false)
                ->ignoreVCS(false)
                ->followLinks()   // resolved and loop-guarded by the caller
                ->sortByName()
                ->in($directory);

            $children = [];

            foreach ($finder as $entry) {
                $children[] = $entry->getPathname();
            }

            return $children;
        } catch (Throwable $e) {
            // Permission denied, a drive that went away mid-scan, a path too
            // long for the platform. Collected, never fatal.
            $errors[] = ['path' => $directory, 'reason' => $e->getMessage()];

            return [];
        }
    }

    /** @param list<array{path:string,reason:string}> $errors */
    private function detect(string $path, array &$errors): ?ProjectSignature
    {
        try {
            return $this->detector->detect($path);
        } catch (Throwable $e) {
            $errors[] = ['path' => $path, 'reason' => 'Type detection failed: '.$e->getMessage()];

            return null;
        }
    }

    /** @param list<string> $skip */
    private function isSkipped(string $path, array $skip): bool
    {
        $name = basename($path);

        // Dot-directories are never projects and are where a walk goes to die:
        // .git alone is thousands of entries. The explicit skip list still names
        // the ones L2 calls out, so the intent stays readable.
        if (str_starts_with($name, '.')) {
            return true;
        }

        $comparable = CanonicalPath::comparable($path);

        foreach ($skip as $entry) {
            $entry = CanonicalPath::normalise((string) $entry);

            if ($entry === '') {
                continue;
            }

            // Entries are either a bare directory name (node_modules) or a
            // relative path (bootstrap/cache); both are matched as a suffix.
            if (str_ends_with($comparable, DIRECTORY_SEPARATOR.CanonicalPath::comparable($entry))) {
                return true;
            }
        }

        return false;
    }

    private function cacheKey(): string
    {
        return 'jetgrid:local:scan:'.md5(implode('|', [
            implode(',', $this->roots()),
            (string) config('jetgrid.local.scan.max_depth'),
            implode(',', (array) config('jetgrid.local.scan.skip', [])),
        ]));
    }
}
