<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Support\Protectable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model implements Protectable
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'sans' => 'array',
            'is_managed' => 'boolean',
            'not_before' => 'datetime',
            'not_after' => 'datetime',
            'last_renewal_attempt_at' => 'datetime',
            'last_renewed_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /** An unmanaged certificate belongs to a project JetGrid did not install. */
    public function isProtectedResource(): bool
    {
        return ! $this->is_managed;
    }

    public function protectionLabel(): string
    {
        return $this->domain;
    }

    /** Days until expiry; negative once expired. */
    public function daysRemaining(): ?int
    {
        if ($this->not_after === null) {
            return null;
        }

        return (int) round(now()->diffInDays($this->not_after, absolute: false));
    }

    public function expiresWithinDays(int $days): bool
    {
        return $this->not_after !== null && $this->not_after->lte(now()->addDays($days));
    }
}
