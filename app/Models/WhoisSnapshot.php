<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhoisSnapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'lookup_ok' => 'boolean',
            'statuses' => 'array',
            'nameservers' => 'array',
            'expires_at' => 'datetime',
            'fetched_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function isFresh(int $hours): bool
    {
        return $this->fetched_at !== null
            && $this->fetched_at->gt(now()->subHours($hours));
    }
}
