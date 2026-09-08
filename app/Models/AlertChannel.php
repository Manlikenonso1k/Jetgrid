<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Feature 6: pluggable channels. type maps to a class in AlertDispatcher. */
class AlertChannel extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'is_active' => 'boolean',
            'last_dispatched_at' => 'datetime',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
