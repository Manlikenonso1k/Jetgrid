<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * L1. One canonical spelling per directory, so the same project cannot be
 * imported twice under two names.
 *
 * The cases this exists for, all of which occur on the machines this module is
 * meant to run on:
 *
 *   - `C:\Users\me\Documents` and `C:/Users/me/Documents` are the same place.
 *   - So are `c:\users\me` and `C:\Users\me`; Windows paths are case-insensitive
 *     but case-preserving, so the stored spelling must keep its case while
 *     comparison ignores it.
 *   - `Documents\My Music` is a junction to `Users\me\Music`. realpath() follows
 *     it, which is what stops the same tree being walked under two roots.
 *   - A UNC path has a leading `\\` that is significant and must survive
 *     separator normalisation.
 *
 * DIRECTORY_SEPARATOR is used for the stored form so that a path JetGrid prints
 * is a path the operator can paste into their own shell.
 */
final class CanonicalPath
{
    /**
     * The canonical absolute path, with symlinks and junctions resolved.
     * Null when the path does not exist or cannot be resolved — a broken
     * junction, or a directory the process may not stat.
     */
    public static function of(string $path): ?string
    {
        $real = @realpath($path);

        return $real === false ? null : self::normalise($real);
    }

    /**
     * Separator- and trailing-slash normalisation only. Used for paths that do
     * not exist yet and for display; it never touches the filesystem.
     */
    public static function normalise(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        $unc = Str::startsWith($path, ['\\\\', '//']);

        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);

        // Collapse repeated separators, then put back the UNC prefix that the
        // collapse just ate.
        $path = preg_replace('#'.preg_quote(DIRECTORY_SEPARATOR, '#').'{2,}#', DIRECTORY_SEPARATOR, $path) ?? $path;

        if ($unc) {
            $path = str_repeat(DIRECTORY_SEPARATOR, 2).ltrim($path, DIRECTORY_SEPARATOR);
        }

        // A trailing separator is meaningless except on a drive or filesystem
        // root, where it is the whole path.
        if (strlen($path) > 1 && ! self::isRoot($path)) {
            $path = rtrim($path, DIRECTORY_SEPARATOR);
        }

        // Drive letters are upper-cased so that one project cannot appear twice
        // as C:\... and c:\...
        if (preg_match('/^[a-z]:/', $path) === 1) {
            $path = ucfirst($path);
        }

        return $path;
    }

    /** The form two paths are compared in. Case-folded only where the OS folds. */
    public static function comparable(string $path): string
    {
        $normalised = self::normalise($path);

        return PHP_OS_FAMILY === 'Windows' ? mb_strtolower($normalised) : $normalised;
    }

    public static function same(string $a, string $b): bool
    {
        return self::comparable($a) === self::comparable($b);
    }

    public static function isWithin(string $child, string $parent): bool
    {
        $childKey = self::comparable($child);
        $parentKey = self::comparable($parent);

        return $childKey === $parentKey
            || str_starts_with($childKey, rtrim($parentKey, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    /** Join without caring which separator the caller used. */
    public static function join(string $base, string ...$parts): string
    {
        return self::normalise(rtrim($base, '\\/').DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $parts));
    }

    private static function isRoot(string $path): bool
    {
        return $path === DIRECTORY_SEPARATOR
            || preg_match('/^[A-Za-z]:'.preg_quote(DIRECTORY_SEPARATOR, '/').'?$/', $path) === 1;
    }
}
