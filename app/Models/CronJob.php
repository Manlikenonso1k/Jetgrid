<?php

namespace App\Models;

use App\Exceptions\ProtectedResourceException;
use App\Support\Protectable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Feature 10. Adopted crons are listed and locked. */
class CronJob extends Model implements Protectable
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_protected' => 'boolean', 'enabled' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (CronJob $job): void {
            if ($job->getOriginal('is_protected')) {
                throw new ProtectedResourceException($job->protectionLabel(), 'modify');
            }
        });

        static::deleting(function (CronJob $job): void {
            if ($job->is_protected) {
                throw new ProtectedResourceException($job->protectionLabel(), 'delete');
            }
        });
    }

    public function isProtectedResource(): bool
    {
        return (bool) $this->is_protected;
    }

    public function protectionLabel(): string
    {
        return (string) ($this->getOriginal('command') ?? $this->command);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
