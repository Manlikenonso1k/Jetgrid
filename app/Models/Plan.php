<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'max_sites' => 'integer',
            'max_databases' => 'integer',
            'max_storage_mb' => 'integer',
            'max_backups' => 'integer',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** A null limit column means unlimited. */
    public function allows(string $limit, int $current): bool
    {
        $max = $this->{$limit};

        return $max === null || $current < $max;
    }

    public function priceLabel(): string
    {
        return $this->price_cents === 0
            ? 'Free'
            : '$'.number_format($this->price_cents / 100, 2).'/mo';
    }
}
