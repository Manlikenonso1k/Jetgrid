<?php

namespace App\Models;

use App\Enums\BeaconColor;
use App\Enums\ManagementMode;
use App\Enums\SiteStatus;
use App\Exceptions\ProtectedResourceException;
use App\Support\Protectable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Site extends Model implements Protectable
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'management_mode' => ManagementMode::class,
            'status' => SiteStatus::class,
            'beacon_color' => BeaconColor::class,
            'is_protected' => 'boolean',
            'maintenance_mode' => 'boolean',
            'basic_auth_enabled' => 'boolean',
            'discovered_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * The only columns that may change on a protected site.
     *
     * Adopted sites still need to be monitored — that is the entire point of
     * adopting them — so the model gate is not "no writes at all", it is "no
     * writes that could change what the server does". Everything here is
     * JetGrid's own observation of the site, never the site itself.
     *
     * @var list<string>
     */
    public const MONITORING_FIELDS = [
        'status', 'health_score', 'beacon_color', 'ram_mb', 'disk_bytes',
        'requests_per_minute', 'pending_updates', 'discovered_at', 'meta',
        'grid_x', 'grid_z', 'display_name', 'updated_at', 'php_version',
        'document_root', 'vhost_path', 'server_user',
    ];

    /**
     * Set only by the deliberate un-protect flow. Never bind this to a request.
     */
    public bool $allowProtectionChange = false;

    protected static function booted(): void
    {
        // Safety constraint #1 at the last possible moment before the database.
        // Even a stray mass-assignment somewhere in the app hits this.
        static::updating(function (Site $site): void {
            if (! $site->getOriginal('is_protected')) {
                return;
            }

            if ($site->allowProtectionChange) {
                return;
            }

            $illegal = array_diff(array_keys($site->getDirty()), self::MONITORING_FIELDS);

            if ($illegal !== []) {
                // protectionLabel(), not $site->domain: the model is already
                // dirty, so the raw attribute would name the value someone
                // tried to set rather than the site being protected.
                throw new ProtectedResourceException(
                    $site->protectionLabel(),
                    'modify ['.implode(', ', $illegal).'] on'
                );
            }
        });

        static::deleting(function (Site $site): void {
            if ($site->is_protected && ! $site->allowProtectionChange) {
                throw new ProtectedResourceException($site->protectionLabel(), 'delete');
            }
        });
    }

    // ---- Protectable ------------------------------------------------------

    public function isProtectedResource(): bool
    {
        return (bool) $this->is_protected;
    }

    public function protectionLabel(): string
    {
        // The ORIGINAL domain, not the attempted one. A refusal triggered by an
        // attempt to rename the site must name the site being protected, not the
        // value someone tried to give it.
        return (string) ($this->getOriginal('domain') ?? $this->domain);
    }

    // ---- Relations --------------------------------------------------------

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class)->latestOfMany('not_after');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class);
    }

    public function healthChecks(): HasMany
    {
        return $this->hasMany(HealthCheck::class);
    }

    public function backups(): HasMany
    {
        return $this->hasMany(Backup::class);
    }

    public function cronJobs(): HasMany
    {
        return $this->hasMany(CronJob::class);
    }

    public function protectedResources(): HasMany
    {
        return $this->hasMany(ProtectedResource::class);
    }

    // ---- Scopes -----------------------------------------------------------

    public function scopeManaged($query)
    {
        return $query->where('is_protected', false);
    }

    public function scopeAdopted($query)
    {
        return $query->where('is_protected', true);
    }

    // ---- Behaviour --------------------------------------------------------

    public function isManaged(): bool
    {
        return ! $this->is_protected;
    }

    public function hasDeploymentInProgress(): bool
    {
        return $this->deployments()
            ->whereIn('status', ['queued', 'running'])
            ->exists();
    }

    /**
     * Deliberately hand a discovered site over to JetGrid's management.
     *
     * This is the ONLY supported way out of protection, it is audited by the
     * caller, and re-running discovery will not undo it (discovery only ever
     * inserts sites it has not seen before).
     */
    public function unprotect(): void
    {
        $this->allowProtectionChange = true;

        $this->forceFill([
            'is_protected' => false,
            'management_mode' => ManagementMode::Managed,
        ])->save();

        $this->allowProtectionChange = false;
    }
}
