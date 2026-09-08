<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Safety constraint #4. One row per config file JetGrid was about to write. */
class ConfigBackup extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['restored_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
