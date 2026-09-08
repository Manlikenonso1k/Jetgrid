<?php

namespace App\Models;

use App\Services\Privilege\BoundCommand;
use App\Services\Server\ProcessResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Request;
use RuntimeException;

/**
 * Safety constraint #7. Append-only.
 *
 * A record is opened before its command runs and closed after. Once closed it
 * cannot be edited or deleted through Eloquent — the model refuses. (Enforce it
 * at the database too if you want the strong version: see docs/AUDIT.md for the
 * GRANT that removes UPDATE/DELETE on this table from the app user.)
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    /** Set while close() is legitimately finalising the record. */
    private bool $closing = false;

    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'dry_run' => 'boolean',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (AuditLog $log): void {
            if (! $log->closing) {
                throw new RuntimeException('Audit log entries are immutable.');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('Audit log entries cannot be deleted.');
        });
    }

    public static function open(
        ?User $user,
        BoundCommand $command,
        ?Model $target,
        bool $dryRun,
        string $driver,
    ): self {
        return self::create([
            'user_id' => $user?->id,
            'user_email' => $user?->email,
            'user_role' => $user?->role?->value,
            'command_key' => $command->definition->key,
            'command_string' => $command->display(),
            'arguments' => $command->args,
            'target_type' => $target ? $target::class : null,
            'target_id' => $target?->getKey(),
            'target_label' => self::labelFor($target),
            'dry_run' => $dryRun,
            'driver' => $driver,
            'outcome' => 'pending',
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }

    public function close(ProcessResult $result): void
    {
        $this->closing = true;

        $this->forceFill([
            'exit_code' => $result->exitCode,
            // Keep the log readable: enough output to diagnose, not a log dump.
            'stdout' => self::truncate($result->stdout),
            'stderr' => self::truncate($result->stderr),
            'duration_ms' => $result->durationMs,
            'outcome' => match (true) {
                $result->skipped => 'dry_run',
                $result->ok() => 'success',
                default => 'failed',
            },
            'completed_at' => now(),
        ])->save();

        $this->closing = false;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    private static function truncate(string $text, int $limit = 65000): string
    {
        return mb_strlen($text) <= $limit
            ? $text
            : mb_substr($text, 0, $limit)."\n… truncated";
    }

    private static function labelFor(?Model $target): ?string
    {
        if ($target === null) {
            return null;
        }

        return $target->getAttribute('domain')
            ?? $target->getAttribute('name')
            ?? class_basename($target).'#'.$target->getKey();
    }
}
