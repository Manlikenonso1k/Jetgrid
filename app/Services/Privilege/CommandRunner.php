<?php

namespace App\Services\Privilege;

use App\Exceptions\ReadOnlyModeException;
use App\Models\AuditLog;
use App\Services\Server\ProcessResult;
use App\Services\Server\ServerDriver;
use App\Support\KillSwitch;
use App\Support\ProtectedResourceGuard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The single place in JetGrid where a command can run.
 *
 * Every privileged operation in the app calls this method, and the checks happen
 * in this order for a reason:
 *
 *   1. whitelist      — an unknown key never becomes a process        (#3)
 *   2. bind + validate— every argument must match its exact pattern   (#3)
 *   3. protected gate — refuse adopted targets, before any role check (#1)
 *   4. kill switch    — refuse all writes while JETGRID_READONLY      (#8)
 *   5. dry run        — render + audit, execute nothing               (#6)
 *   6. audit open     — the record exists BEFORE the process starts   (#7)
 *   7. execute        — argv array, never a shell string
 *   8. audit close    — exit code, stdout, stderr, duration           (#7)
 *
 * Step 6 before step 7 is deliberate: a command that hangs or crashes the
 * process still leaves a trace of having been attempted.
 */
class CommandRunner
{
    public function __construct(
        private readonly CommandRegistry $registry,
        private readonly ServerDriver $driver,
        private readonly ProtectedResourceGuard $guard,
        private readonly KillSwitch $killSwitch,
    ) {}

    /**
     * @param  array<string,string|int>  $args
     * @param  Model|null  $target  the site/resource this acts on, for the protected gate
     * @param  bool|null  $dryRun  null = fall back to configuration
     */
    public function run(
        string $key,
        array $args = [],
        ?Model $target = null,
        ?bool $dryRun = null,
        int $timeoutSeconds = 60,
    ): CommandOutcome {
        // 1 + 2
        $definition = $this->registry->get($key);
        $bound = $definition->bind($args);

        // 3 — hard gate. Note this runs for reads too when a target is given:
        //     a read is allowed, but only through commands marked isWrite=false.
        if ($definition->isWrite) {
            $this->guard->assertWritable($target, "run [{$key}] against");
        }

        // 4
        if ($definition->isWrite && $this->readOnly()) {
            throw new ReadOnlyModeException($key);
        }

        // 5
        $isDryRun = $this->resolveDryRun($dryRun, $definition);

        // 6
        $audit = AuditLog::open(
            user: Auth::user(),
            command: $bound,
            target: $target,
            dryRun: $isDryRun,
            driver: $this->driver->name(),
        );

        // 7
        $result = $isDryRun
            ? ProcessResult::skipped('Dry run: command was rendered and audited, not executed.')
            : $this->driver->run($bound, $timeoutSeconds);

        // 8
        $audit->close($result);

        return new CommandOutcome($bound, $result, $isDryRun, $audit);
    }

    /**
     * Render a command without running it or writing an audit record. Used to
     * populate the confirmation dialog required by safety constraint #6.
     *
     * @param  array<string,string|int>  $args
     */
    public function preview(string $key, array $args = []): string
    {
        return $this->registry->get($key)->bind($args)->display();
    }

    public function readOnly(): bool
    {
        return $this->killSwitch->isReadOnly();
    }

    private function resolveDryRun(?bool $requested, PrivilegedCommand $definition): bool
    {
        // A forced dry run cannot be argued with.
        if (config('jetgrid.force_dry_run', false)) {
            return true;
        }

        // Reads are cheap and side-effect free; dry-running them would just make
        // the dashboard permanently empty.
        if (! $definition->isWrite) {
            return false;
        }

        return $requested ?? (bool) config('jetgrid.dry_run_default', true);
    }
}
