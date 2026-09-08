<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Metric extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'value' => 'float'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** Server-wide metrics carry no site. */
    public function scopeServer($query)
    {
        return $query->whereNull('site_id');
    }
}
