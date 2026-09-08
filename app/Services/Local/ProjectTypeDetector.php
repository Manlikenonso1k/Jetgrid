<?php

namespace App\Services\Local;

use App\Enums\InstallState;
use App\Enums\LocalProjectType;

/**
 * L3. Works out what a directory is by its filesystem signature, and nothing
 * else. It reads files; it never executes anything and never writes.
 *
 * On the ordering: L3 lists "Generic PHP: composer.json alone" and "Static:
 * index.html with no other marker" in the middle and at the end of its table
 * respectively, but both are defined by the ABSENCE of a stronger signal. They
 * are therefore evaluated last — a Laravel project also has a composer.json, and
 * a Next.js project also has an index.html once it has been built. The
 * authoritative order is the case order of LocalProjectType.
 *
 * A directory matching nothing returns null. That is the common case: most
 * folders in a Documents directory are not projects, and inventing a type for
 * them would fill the grid with houses nobody wants.
 */
class ProjectTypeDetector
{
    public function detect(string $path): ?ProjectSignature
    {
        if (! is_dir($path)) {
            return null;
        }

        $composer = $this->json($path, 'composer.json');
        $package = $this->json($path, 'package.json');

        [$type, $marker, $framework, $version] = $this->classify($path, $composer, $package)
            ?? [null, null, null, null];

        if ($type === null) {
            return null;
        }

        [$state, $blockers] = $this->installState($path, $type, $composer, $package);

        return new ProjectSignature(
            // The folder name, not the manifest name: every Laravel skeleton
            // calls itself "laravel/laravel", which would give six houses the
            // same label. The declared name is kept alongside it instead.
            type: $type,
            name: basename($path),
            declaredName: $this->declaredName($composer, $package),
            marker: $marker,
            framework: $framework,
            version: $version,
            hasDocker: $this->composeFile($path) !== null,
            installState: $state,
            blockers: $blockers,
            packageManager: $package === null ? null : $this->packageManager($path),
            devScript: $this->devScript($package),
        );
    }

    /** The compose file, if this project has one. Docker is a modifier, not a type. */
    public function composeFile(string $path): ?string
    {
        foreach (['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'] as $candidate) {
            if (is_file($path.DIRECTORY_SEPARATOR.$candidate)) {
                return $path.DIRECTORY_SEPARATOR.$candidate;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $composer
     * @param  array<string,mixed>|null  $package
     * @return array{0:LocalProjectType,1:string,2:string|null,3:string|null}|null
     */
    private function classify(string $path, ?array $composer, ?array $package): ?array
    {
        $has = fn (string $relative): bool => is_file($path.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative));

        if ($has('artisan') && $this->requires($composer, 'laravel/framework')) {
            return [LocalProjectType::Laravel, 'artisan', null, $this->requirement($composer, 'laravel/framework')];
        }

        if ($has('bin/console') && $this->requires($composer, 'symfony/framework-bundle')) {
            return [LocalProjectType::Symfony, 'bin/console', null, $this->requirement($composer, 'symfony/framework-bundle')];
        }

        foreach (['wp-config.php', 'wp-settings.php'] as $wp) {
            if ($has($wp)) {
                return [LocalProjectType::WordPress, $wp, null, null];
            }
        }

        if ($has('manage.py')) {
            return [LocalProjectType::Django, 'manage.py', null, null];
        }

        if ($has('Gemfile') && $has('config/application.rb')) {
            return [LocalProjectType::Rails, 'config/application.rb', null, null];
        }

        if ($this->dependsOn($package, 'next')) {
            return [LocalProjectType::Nextjs, 'package.json', 'Next.js', $this->dependency($package, 'next')];
        }

        foreach (['nuxt.config.js', 'nuxt.config.ts', 'nuxt.config.mjs'] as $nuxt) {
            if ($has($nuxt)) {
                return [LocalProjectType::Nuxt, $nuxt, 'Nuxt', $this->dependency($package, 'nuxt')];
            }
        }

        foreach (['vite.config.js', 'vite.config.ts', 'vite.config.mjs'] as $vite) {
            if ($has($vite)) {
                // Vite is a build tool, not a framework, so the framework name
                // has to come from what the project actually depends on.
                return [LocalProjectType::Vite, $vite, $this->viteFramework($package), $this->dependency($package, 'vite')];
            }
        }

        if ($package !== null && $this->devScript($package) !== null) {
            return [LocalProjectType::Node, 'package.json', null, null];
        }

        if ($this->pythonWebFramework($path) !== null) {
            return [LocalProjectType::Flask, $this->pythonMarker($path) ?? 'requirements.txt', $this->pythonWebFramework($path), null];
        }

        if ($has('go.mod')) {
            return [LocalProjectType::Go, 'go.mod', null, $this->goVersion($path)];
        }

        if ($has('Cargo.toml')) {
            return [LocalProjectType::Rust, 'Cargo.toml', null, null];
        }

        if ($composer !== null) {
            return [LocalProjectType::Php, 'composer.json', null, null];
        }

        if ($has('index.php')) {
            return [LocalProjectType::Php, 'index.php', null, null];
        }

        if ($has('index.html')) {
            return [LocalProjectType::Static, 'index.html', null, null];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $composer
     * @param  array<string,mixed>|null  $package
     * @return array{0:InstallState,1:list<string>}
     */
    private function installState(string $path, LocalProjectType $type, ?array $composer, ?array $package): array
    {
        $blockers = [];

        $dependenciesMissing = false;

        if ($composer !== null && ! is_dir($path.DIRECTORY_SEPARATOR.'vendor')) {
            $blockers[] = 'vendor/ is missing — composer install has not been run.';
            $dependenciesMissing = true;
        }

        if ($package !== null && ! is_dir($path.DIRECTORY_SEPARATOR.'node_modules')) {
            $blockers[] = 'node_modules/ is missing — the package manager has not been run.';
            $dependenciesMissing = true;
        }

        $notConfigured = $type === LocalProjectType::Laravel
            && ! is_file($path.DIRECTORY_SEPARATOR.'.env');

        if ($notConfigured) {
            $blockers[] = '.env is missing — the app has no configuration and will not boot.';
        }

        // Dependencies win the label because they are the blocker you clear
        // first: without vendor/, a missing .env cannot even be diagnosed. Both
        // are reported in $blockers either way, so this decides which reason the
        // beacon shows, not which facts survive.
        $state = match (true) {
            $dependenciesMissing => InstallState::DependenciesMissing,
            $notConfigured => InstallState::NotConfigured,
            default => InstallState::Ready,
        };

        return [$state, $blockers];
    }

    /**
     * @param  array<string,mixed>|null  $composer
     * @param  array<string,mixed>|null  $package
     */
    private function declaredName(?array $composer, ?array $package): ?string
    {
        foreach ([$package['name'] ?? null, $composer['name'] ?? null] as $declared) {
            if (! is_string($declared) || trim($declared) === '') {
                continue;
            }

            // composer names are vendor/package and npm names may be @scope/name.
            // The prefix is noise on a grid where every house needs a short label.
            $slash = strrpos($declared, '/');

            return trim($slash === false ? $declared : substr($declared, $slash + 1));
        }

        return null;
    }

    /** @param array<string,mixed>|null $package */
    private function viteFramework(?array $package): ?string
    {
        foreach ([
            'react' => 'React',
            'vue' => 'Vue',
            'svelte' => 'Svelte',
            'solid-js' => 'Solid',
            'preact' => 'Preact',
            '@angular/core' => 'Angular',
        ] as $dependency => $label) {
            if ($this->dependsOn($package, $dependency)) {
                return $label;
            }
        }

        return null;
    }

    /** @param array<string,mixed>|null $package */
    private function devScript(?array $package): ?string
    {
        $scripts = $package['scripts'] ?? null;

        if (! is_array($scripts)) {
            return null;
        }

        // dev before start: a project with both means `dev` is the one with hot
        // reload, which is what a local grid is for.
        foreach (['dev', 'start', 'serve'] as $candidate) {
            if (isset($scripts[$candidate]) && is_string($scripts[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /** Which lockfile is present decides the package manager. L6 requires this. */
    private function packageManager(string $path): string
    {
        return match (true) {
            is_file($path.DIRECTORY_SEPARATOR.'pnpm-lock.yaml') => 'pnpm',
            is_file($path.DIRECTORY_SEPARATOR.'yarn.lock') => 'yarn',
            default => 'npm',
        };
    }

    private function pythonMarker(string $path): ?string
    {
        foreach (['requirements.txt', 'pyproject.toml'] as $marker) {
            if (is_file($path.DIRECTORY_SEPARATOR.$marker)) {
                return $marker;
            }
        }

        return null;
    }

    private function pythonWebFramework(string $path): ?string
    {
        $marker = $this->pythonMarker($path);

        if ($marker === null) {
            return null;
        }

        $contents = @file_get_contents($path.DIRECTORY_SEPARATOR.$marker);

        if ($contents === false) {
            return null;
        }

        return match (true) {
            preg_match('/\bfastapi\b/i', $contents) === 1 => 'FastAPI',
            preg_match('/\bflask\b/i', $contents) === 1 => 'Flask',
            default => null,
        };
    }

    private function goVersion(string $path): ?string
    {
        $contents = @file_get_contents($path.DIRECTORY_SEPARATOR.'go.mod');

        if ($contents === false) {
            return null;
        }

        return preg_match('/^go\s+([0-9.]+)/m', $contents, $m) === 1 ? $m[1] : null;
    }

    /** @param array<string,mixed>|null $composer */
    private function requires(?array $composer, string $package): bool
    {
        return $this->requirement($composer, $package) !== null;
    }

    /** @param array<string,mixed>|null $composer */
    private function requirement(?array $composer, string $package): ?string
    {
        foreach (['require', 'require-dev'] as $section) {
            $value = $composer[$section][$package] ?? null;

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string,mixed>|null $package */
    private function dependsOn(?array $package, string $dependency): bool
    {
        return $this->dependency($package, $dependency) !== null;
    }

    /** @param array<string,mixed>|null $package */
    private function dependency(?array $package, string $dependency): ?string
    {
        foreach (['dependencies', 'devDependencies'] as $section) {
            $value = $package[$section][$dependency] ?? null;

            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /**
     * A malformed manifest is not an error worth aborting a scan for — half the
     * folders on a workstation contain something half-finished.
     *
     * @return array<string,mixed>|null
     */
    private function json(string $path, string $file): ?array
    {
        $full = $path.DIRECTORY_SEPARATOR.$file;

        if (! is_file($full)) {
            return null;
        }

        $contents = @file_get_contents($full);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : [];
    }
}
