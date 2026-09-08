<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Safety constraint #8, resolved fail-closed.
 *
 * There are two inputs and the env one is a floor, not a default:
 *
 *   JETGRID_READONLY=true   (the shipped default)
 *       Writes are off. A god_mode user flipping the UI switch does nothing,
 *       unless the operator has ALSO set JETGRID_ALLOW_RUNTIME_UNLOCK=true —
 *       an explicit, SSH-only opt-in to letting the panel unlock itself.
 *
 *   JETGRID_READONLY=false
 *       Writes are permitted, and the UI switch still works as a panic button
 *       that takes effect on the next request with no deploy.
 *
 * Every failure path returns true. If the settings table is missing, corrupt or
 * unreachable, JetGrid is read-only.
 */
class KillSwitch
{
    public const SETTING = 'readonly';

    public function isReadOnly(): bool
    {
        try {
            $envReadOnly = (bool) config('jetgrid.readonly', true);
            $runtimeReadOnly = Setting::flag(self::SETTING, true);

            if ($envReadOnly && ! config('jetgrid.allow_runtime_unlock', false)) {
                return true;
            }

            return $runtimeReadOnly;
        } catch (Throwable) {
            return true;
        }
    }

    public function isWritable(): bool
    {
        return ! $this->isReadOnly();
    }

    /** True when the UI switch can actually change anything. */
    public function runtimeUnlockPermitted(): bool
    {
        return ! config('jetgrid.readonly', true)
            || (bool) config('jetgrid.allow_runtime_unlock', false);
    }

    /** Explains the current state in the words the operator needs. */
    public function explain(): string
    {
        if (! $this->isReadOnly()) {
            return 'Write operations are ENABLED. Every privileged command is still whitelisted, previewed and audited.';
        }

        if (! $this->runtimeUnlockPermitted()) {
            return 'Read-only: JETGRID_READONLY=true in .env. The in-app switch cannot lift this. Set JETGRID_ALLOW_RUNTIME_UNLOCK=true over SSH first if you want the panel to be able to unlock itself.';
        }

        return 'Read-only: the in-app switch is on. A god_mode user can turn it off in Settings.';
    }
}
