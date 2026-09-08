<?php

namespace App\Enums;

/**
 * The L3 project types.
 *
 * Declaration order IS the detection priority order: ProjectTypeDetector walks
 * cases() top to bottom and takes the first match, so `laravel` must be tried
 * before `php` (every Laravel app also has a composer.json) and `nextjs` before
 * `node` (every Next app also has a dev script). Reordering these cases changes
 * detection behaviour — that is deliberate, and it is why the table lives here
 * rather than being scattered across the detector.
 *
 * Docker is not a case. L3 calls it "a modifier, not a type", so it is a boolean
 * on the detection result instead.
 */
enum LocalProjectType: string
{
    case Laravel = 'laravel';
    case Symfony = 'symfony';
    case WordPress = 'wordpress';
    case Django = 'django';
    case Rails = 'rails';
    case Nextjs = 'nextjs';
    case Nuxt = 'nuxt';
    case Vite = 'vite';
    case Node = 'node';
    case Flask = 'flask';
    case Go = 'go';
    case Rust = 'rust';
    case Php = 'php';
    case Static = 'static';

    public function label(): string
    {
        return match ($this) {
            self::Laravel => 'Laravel',
            self::Symfony => 'Symfony',
            self::WordPress => 'WordPress',
            self::Django => 'Django',
            self::Rails => 'Rails',
            self::Nextjs => 'Next.js',
            self::Nuxt => 'Nuxt',
            self::Vite => 'Vite',
            self::Node => 'Node',
            self::Flask => 'Flask / FastAPI',
            self::Go => 'Go',
            self::Rust => 'Rust',
            self::Php => 'Generic PHP',
            self::Static => 'Static',
        };
    }

    /** Which ecosystem's dependency directory decides the install state. */
    public function ecosystem(): string
    {
        return match ($this) {
            self::Laravel, self::Symfony, self::WordPress, self::Php => 'php',
            self::Nextjs, self::Nuxt, self::Vite, self::Node => 'node',
            self::Django, self::Flask => 'python',
            self::Rails => 'ruby',
            self::Go => 'go',
            self::Rust => 'rust',
            self::Static => 'none',
        };
    }

    public function defaultPort(): int
    {
        return (int) (config('jetgrid.local.default_ports.'.$this->value) ?? 8000);
    }

    /**
     * The LocalCommandRegistry key that starts this type, or null when JetGrid
     * has no safe default for it. Null means the UI must ask for an explicit
     * command rather than inventing one — see L6.
     */
    public function startCommandKey(): ?string
    {
        return match ($this) {
            self::Laravel => 'start.laravel',
            self::Symfony, self::WordPress, self::Php, self::Static => 'start.php_builtin',
            self::Nextjs, self::Nuxt, self::Vite, self::Node => 'start.node',
            self::Django => 'start.django',
            self::Flask => null,
            self::Rails => 'start.rails',
            self::Go => 'start.go',
            self::Rust => null,
        };
    }
}
