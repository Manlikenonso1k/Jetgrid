<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Backup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'retention_until' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Feature 9: an unverified backup is a hypothesis, so it does not count
     * toward the backup-freshness component of the health score.
     */
    public function isVerified(): bool
    {
        return $this->verified_at !== null && $this->verify_result === 'passed';
    }

    public function sizeLabel(): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $this->size_bytes;
        $i = 0;

        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }

        return round($size, 1).' '.$units[$i];
    }
}
