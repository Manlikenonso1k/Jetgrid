<?php

namespace App\Services\Local;

use App\Enums\LocalProjectType;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * L4. Works out which port a project WOULD run on, without starting it.
 *
 * Everything here is a read. The most specific evidence wins, and the answer
 * always carries the reason, because "8000" from a framework default and "8000"
 * from the project's own APP_URL are different claims.
 *
 * Values read out of a discovered project's .env are treated as untrusted text.
 * They are parsed with a regex, never with an interpolating dotenv loader, and
 * anything that is not a plausible port number is discarded rather than
 * corrected.
 */
class PortResolver
{
    /** A .env big enough to be something other than a .env is not read. */
    private const MAX_ENV_BYTES = 256 * 1024;

    private const MAX_COMPOSE_BYTES = 512 * 1024;

    public function resolve(string $path, ProjectSignature $signature, ?int $override = null): PortResolution
    {
        if ($override !== null && $this->isValidPort($override)) {
            return new PortResolution($override, PortResolution::SOURCE_OVERRIDE);
        }

        return $this->fromProject($path, $signature)
            ?? $this->fromCompose($path)
            ?? new PortResolution($signature->type->defaultPort(), PortResolution::SOURCE_DEFAULT, $signature->type->label());
    }

    /** Every host port a compose file publishes, for the port-collision report. */
    public function composeHostPorts(string $path): array
    {
        $file = $this->composeFile($path);

        if ($file === null) {
            return [];
        }

        $parsed = $this->parseYaml($file);

        if ($parsed === null) {
            return [];
        }

        $ports = [];

        foreach ((array) ($parsed['services'] ?? []) as $service) {
            foreach ((array) ($service['ports'] ?? []) as $mapping) {
                $port = $this->hostPortFromMapping($mapping);

                if ($port !== null) {
                    $ports[] = $port;
                }
            }
        }

        return array_values(array_unique($ports));
    }

    private function fromProject(string $path, ProjectSignature $signature): ?PortResolution
    {
        return match ($signature->type) {
            LocalProjectType::Laravel => $this->fromAppUrl($path),
            LocalProjectType::Nextjs,
            LocalProjectType::Nuxt,
            LocalProjectType::Vite,
            LocalProjectType::Node => $this->fromNodeEnv($path) ?? $this->fromDevScript($path, $signature),
            default => null,
        };
    }

    /** Laravel: APP_URL carries the port when the dev server is not on the default. */
    private function fromAppUrl(string $path): ?PortResolution
    {
        $url = $this->envValue($path, '.env', 'APP_URL');

        if ($url === null) {
            return null;
        }

        $port = parse_url($url, PHP_URL_PORT);

        if (! is_int($port) || ! $this->isValidPort($port)) {
            return null;
        }

        return new PortResolution($port, PortResolution::SOURCE_ENV, 'APP_URL='.$url);
    }

    private function fromNodeEnv(string $path): ?PortResolution
    {
        foreach (['.env.local', '.env'] as $file) {
            $value = $this->envValue($path, $file, 'PORT');

            if ($value !== null && ctype_digit($value) && $this->isValidPort((int) $value)) {
                return new PortResolution((int) $value, PortResolution::SOURCE_ENV, $file.' PORT='.$value);
            }
        }

        return null;
    }

    /** `"dev": "vite --port 5180"` and `next dev -p 3001` both live here. */
    private function fromDevScript(string $path, ProjectSignature $signature): ?PortResolution
    {
        $script = $signature->devScript;

        if ($script === null) {
            return null;
        }

        $package = @file_get_contents($path.DIRECTORY_SEPARATOR.'package.json');

        if ($package === false) {
            return null;
        }

        $decoded = json_decode($package, true);
        $line = $decoded['scripts'][$script] ?? null;

        if (! is_string($line)) {
            return null;
        }

        if (preg_match('/(?:--port[= ]|(?<![\w-])-p[= ])(\d{2,5})/', $line, $m) !== 1) {
            return null;
        }

        $port = (int) $m[1];

        return $this->isValidPort($port)
            ? new PortResolution($port, PortResolution::SOURCE_SCRIPT, $script.': '.$line)
            : null;
    }

    private function fromCompose(string $path): ?PortResolution
    {
        $ports = $this->composeHostPorts($path);

        if ($ports === []) {
            return null;
        }

        // The lowest published port is the one a human would type into a
        // browser; the others are databases and mail catchers.
        sort($ports);

        return new PortResolution($ports[0], PortResolution::SOURCE_COMPOSE, implode(', ', $ports));
    }

    /**
     * A compose mapping is "8080:80", "127.0.0.1:8080:80", "3000", or a long-form
     * map with a `published` key. Only the host side is of interest.
     */
    private function hostPortFromMapping(mixed $mapping): ?int
    {
        if (is_array($mapping)) {
            $published = $mapping['published'] ?? null;

            return is_numeric($published) && $this->isValidPort((int) $published) ? (int) $published : null;
        }

        if (! is_string($mapping) && ! is_int($mapping)) {
            return null;
        }

        $parts = explode(':', (string) $mapping);

        // No colon: a container port with an ephemeral host port. Nothing to
        // resolve, and guessing would be worse than saying nothing.
        if (count($parts) < 2) {
            return null;
        }

        // "8080:80" -> [0]; "127.0.0.1:8080:80" -> [1]. The host port is always
        // the one before the last.
        $host = $parts[count($parts) - 2];

        // A range ("8000-8010:80") resolves to its first port.
        $host = explode('-', $host)[0];

        return ctype_digit($host) && $this->isValidPort((int) $host) ? (int) $host : null;
    }

    private function composeFile(string $path): ?string
    {
        foreach (['docker-compose.yml', 'docker-compose.yaml', 'compose.yml', 'compose.yaml'] as $candidate) {
            $full = $path.DIRECTORY_SEPARATOR.$candidate;

            if (is_file($full)) {
                return $full;
            }
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function parseYaml(string $file): ?array
    {
        if ((@filesize($file) ?: 0) > self::MAX_COMPOSE_BYTES) {
            return null;
        }

        try {
            $parsed = Yaml::parseFile($file);
        } catch (ParseException) {
            // A compose file that does not parse is the project's problem, not
            // a reason for the scan to fail.
            return null;
        }

        return is_array($parsed) ? $parsed : null;
    }

    /**
     * Read one key out of a project's .env.
     *
     * Deliberately not phpdotenv: that resolves ${VAR} references against the
     * running process's own environment, which would mix JetGrid's configuration
     * into a foreign project's values.
     */
    private function envValue(string $path, string $file, string $key): ?string
    {
        $full = $path.DIRECTORY_SEPARATOR.$file;

        if (! is_file($full) || (@filesize($full) ?: 0) > self::MAX_ENV_BYTES) {
            return null;
        }

        $contents = @file_get_contents($full);

        if ($contents === false) {
            return null;
        }

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            $line = ltrim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (preg_match('/^(?:export\s+)?'.preg_quote($key, '/').'\s*=\s*(.*)$/', $line, $m) !== 1) {
                continue;
            }

            $value = trim($m[1]);
            $value = trim($value, "\"'");

            return $value === '' ? null : $value;
        }

        return null;
    }

    private function isValidPort(int $port): bool
    {
        return $port >= 1 && $port <= 65535;
    }
}
