<?php

namespace Tests\Unit;

use App\Support\CanonicalPath;
use Tests\TestCase;

/** L1. One spelling per directory, so nothing is imported twice. */
class CanonicalPathTest extends TestCase
{
    public function test_separators_are_normalised_to_the_platform(): void
    {
        $mixed = 'C:/Users/ayomi\\Documents/app';

        $this->assertSame(
            'C:'.DIRECTORY_SEPARATOR.'Users'.DIRECTORY_SEPARATOR.'ayomi'.DIRECTORY_SEPARATOR.'Documents'.DIRECTORY_SEPARATOR.'app',
            CanonicalPath::normalise($mixed),
        );
    }

    public function test_repeated_separators_collapse(): void
    {
        $this->assertSame(
            DIRECTORY_SEPARATOR.'srv'.DIRECTORY_SEPARATOR.'app',
            CanonicalPath::normalise('/srv//////app'),
        );
    }

    /** A UNC path's leading double separator is significant and must survive. */
    public function test_a_unc_prefix_is_preserved(): void
    {
        $normalised = CanonicalPath::normalise('\\\\fileserver\\share\\project');

        $this->assertStringStartsWith(str_repeat(DIRECTORY_SEPARATOR, 2), $normalised);
        $this->assertStringContainsString('fileserver', $normalised);
    }

    public function test_a_trailing_separator_is_dropped_but_a_root_survives(): void
    {
        $this->assertSame('C:'.DIRECTORY_SEPARATOR.'projects', CanonicalPath::normalise('C:\\projects\\'));
        $this->assertSame(DIRECTORY_SEPARATOR, CanonicalPath::normalise('/'));
    }

    public function test_a_drive_letter_is_upper_cased_so_one_project_has_one_identity(): void
    {
        $this->assertTrue(CanonicalPath::same('c:\\users\\me\\app', 'C:\\Users\\me\\app'));
    }

    public function test_spaces_in_directory_names_are_left_alone(): void
    {
        $this->assertStringContainsString('My Projects', CanonicalPath::normalise('C:\\Users\\me\\My Projects\\shop'));
    }

    public function test_containment_is_by_segment_not_by_prefix(): void
    {
        $parent = 'C:'.DIRECTORY_SEPARATOR.'code';

        $this->assertTrue(CanonicalPath::isWithin('C:'.DIRECTORY_SEPARATOR.'code'.DIRECTORY_SEPARATOR.'shop', $parent));
        $this->assertTrue(CanonicalPath::isWithin($parent, $parent));

        // "codex" starts with "code" but is not inside it.
        $this->assertFalse(CanonicalPath::isWithin('C:'.DIRECTORY_SEPARATOR.'codex'.DIRECTORY_SEPARATOR.'shop', $parent));
    }

    public function test_a_real_directory_resolves_and_a_missing_one_does_not(): void
    {
        $this->assertSame(CanonicalPath::normalise(base_path()), CanonicalPath::of(base_path()));
        $this->assertNull(CanonicalPath::of(base_path('this-does-not-exist-'.uniqid())));
    }

    /** realpath() is what collapses a junction, and is why a scan sees one tree. */
    public function test_a_relative_walk_resolves_to_the_same_canonical_path(): void
    {
        $viaParent = base_path('app'.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'config');

        $this->assertSame(CanonicalPath::of(base_path('config')), CanonicalPath::of($viaParent));
    }
}
