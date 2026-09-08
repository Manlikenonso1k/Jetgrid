<?php

namespace App\Models;

use App\Exceptions\ProtectedResourceException;
use App\Support\Protectable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A thing discovery found and JetGrid will never touch.
 *
 * Every instance is protected by definition, so isProtectedResource() is a
 * constant true rather than a column: there is no state in which one of these
 * becomes writable.
 */
class ProtectedResource extends Model implements Protectable
{
    protected $guarded = [];

    /** @var list<string> */
    public const OBSERVATION_FIELDS = [
        'fingerprint', 'excerpt', 'parsed', 'last_seen_at', 'site_id', 'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'parsed' => 'array',
            'discovered_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (ProtectedResource $resource): void {
            $illegal = array_diff(array_keys($resource->getDirty()), self::OBSERVATION_FIELDS);

            if ($illegal !== []) {
                throw new ProtectedResourceException($resource->protectionLabel(), 'modify');
            }
        });
    }

    public function isProtectedResource(): bool
    {
        return true;
    }

    public function protectionLabel(): string
    {
        return (string) ($this->getOriginal('name') ?? $this->name);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** True when the file changed on disk since discovery last saw it. */
    public function hasDrifted(?string $currentFingerprint): bool
    {
        return $currentFingerprint !== null
            && $this->fingerprint !== null
            && $currentFingerprint !== $this->fingerprint;
    }
}
