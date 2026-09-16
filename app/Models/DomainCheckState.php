<?php

namespace App\Models;

use App\Enums\CheckStatus;
use App\Enums\CheckType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DomainCheckState extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'check_type' => CheckType::class,
            'state' => CheckStatus::class,
            'failing_since' => 'datetime',
            'last_alerted_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
