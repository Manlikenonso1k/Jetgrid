<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainBaseline extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'nameservers' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
