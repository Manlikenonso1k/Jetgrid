<?php

namespace App\Services\Local;

use App\Exceptions\LocalModeDisabledException;
use App\Services\Local\Platform\PlatformDetector;

/**
 * L0. Four independent checks, ALL of which must pass before anything in this
 * module may run.
 *
 *   1. config('jetgrid.mode') === 'local'   — a deliberate declaration
 *   2. app()->environment('local')          — a second, separate declaration
 *   3. no production marker file            — an operator veto that outlives deploys
 *   4. PlatformDetector::isLocalWorkstation() — evidence, not declaration
 *
 * They are independent on purpose. The first two are both "somebody wrote a
 * value in a file", so on their own they would be one check with two spellings;
 * the third survives a config change and the fourth survives an operator who
 * copied the wrong .env onto a server. Getting local mode wrong means JetGrid
 * starting and stopping processes on a live machine, so the gate is designed to
 * need only one of the four to be right in order to refuse.
 *
 * This is the single place the answer is computed. The middleware, the artisan
 * commands and the process controller all call it — none of them re-implements
 * any part of it.
 */
class LocalModeGate
{
    public const CHECK_MODE = 'jetgrid.mode is not "local"';

    public const CHECK_ENVIRONMENT = 'APP_ENV is not "local"';

    public const CHECK_MARKER = 'a production marker file is present';

    public const CHECK_WORKSTATION = 'this host looks like a headless server';

    public function __construct(private readonly PlatformDetector $platform) {}

    public function passes(): bool
    {
        return $this->failures() === [];
    }

    /**
     * Which checks said no. Empty means the gate is open.
     *
     * @return list<string>
     */
    public function failures(): array
    {
        $failed = [];

        if (config('jetgrid.mode') !== 'local') {
            $failed[] = self::CHECK_MODE;
        }

        if (! app()->environment('local')) {
            $failed[] = self::CHECK_ENVIRONMENT;
        }

        if ($this->productionMarker() !== null) {
            $failed[] = self::CHECK_MARKER;
        }

        if (! $this->platform->isLocalWorkstation()) {
            $failed[] = self::CHECK_WORKSTATION;
        }

        return $failed;
    }

    /**
     * Every check with its verdict, for `jetgrid:doctor`.
     *
     * The labels are the CONDITION, not the failure, because a report reading
     * "APP_ENV is not local ... pass" is a sentence nobody can act on. The
     * failure constants keep their own wording for the exception message, where
     * they are only ever printed when they did fail.
     *
     * @return array<string,bool>
     */
    public function report(): array
    {
        $failed = $this->failures();

        $verdict = static fn (string $check): bool => ! in_array($check, $failed, true);

        return [
            'config jetgrid.mode is "local"' => $verdict(self::CHECK_MODE),
            'APP_ENV is "local"' => $verdict(self::CHECK_ENVIRONMENT),
            'no production marker file' => $verdict(self::CHECK_MARKER),
            'host is a workstation, not a server' => $verdict(self::CHECK_WORKSTATION),
        ];
    }

    /** The marker file that vetoed local mode, if any. */
    public function productionMarker(): ?string
    {
        foreach ((array) config('jetgrid.local.production_markers', []) as $path) {
            if (@file_exists($path)) {
                return $path;
            }
        }

        return null;
    }

    /** @throws LocalModeDisabledException */
    public function assertOpen(string $action = 'local control'): void
    {
        $failed = $this->failures();

        if ($failed !== []) {
            throw new LocalModeDisabledException($failed, $action);
        }
    }
}
