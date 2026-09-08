<?php

namespace App\Support;

use App\Exceptions\PathEscapeException;

/**
 * Safety constraint #2, for the file manager and every config write.
 *
 * Two checks, because neither is sufficient alone:
 *
 *   1. LEXICAL. The path is normalised textually — `.` and `..` segments are
 *      resolved without touching the filesystem — and must land inside an
 *      allowed root. This works for paths that do not exist yet (a file about
 *      to be created) and for paths belonging to a different machine, which
 *      matters because these are always *target server* paths and JetGrid may
 *      be reasoning about them from somewhere else entirely.
 *
 *   2. PHYSICAL. If the path exists locally, its realpath must ALSO land inside
 *      the root. This is what catches a symlink inside a site directory that
 *      points at /etc — something the lexical check cannot see.
 *
 * An earlier version relied on realpath alone. That was wrong: on any host where
 * the target paths do not exist, realpath returns false, the fallback drifted,
 * and legitimate paths were rejected while the check silently stopped meaning
 * anything.
 */
class PathGuard
{
    /** @var list<string> */
    private array $roots;

    /** @param list<string> $roots */
    public function __construct(array $roots)
    {
        $this->roots = array_values(array_filter(array_map(
            fn (?string $root): string => $this->lexicalNormalise((string) $root),
            $roots,
        )));
    }

    public function assertInside(string $path): string
    {
        $normalised = $this->lexicalNormalise($path);

        if (! $this->withinAnyRoot($normalised)) {
            throw new PathEscapeException($path, implode(', ', $this->roots));
        }

        // Only meaningful when the path exists on THIS machine.
        $real = @realpath($normalised);

        if ($real !== false && ! $this->withinAnyRoot($this->lexicalNormalise($real))) {
            throw new PathEscapeException($path, implode(', ', $this->roots));
        }

        return $normalised;
    }

    public function isInside(string $path): bool
    {
        try {
            $this->assertInside($path);

            return true;
        } catch (PathEscapeException) {
            return false;
        }
    }

    private function withinAnyRoot(string $path): bool
    {
        foreach ($this->roots as $root) {
            if ($path === $root || str_starts_with($path, $root.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve `.` and `..` textually. A `..` that would climb above the root is
     * dropped rather than escaping, so "/a/../../etc" normalises to "/etc" and
     * is then rejected by the containment check on its merits.
     */
    private function lexicalNormalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $absolute = str_starts_with($path, '/');

        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($out);

                continue;
            }

            $out[] = $segment;
        }

        $joined = implode('/', $out);

        return $absolute ? '/'.$joined : ($joined === '' ? '.' : $joined);
    }
}
