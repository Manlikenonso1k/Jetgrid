<?php

namespace App\Models;

use App\Enums\Role;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'role', 'plan_id',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Every role gets in; what they can DO is decided by policies. Blocking
        // at the door would only push authorisation into the UI layer, which is
        // exactly what Feature 8 says not to do.
        return true;
    }

    // ---- Relations --------------------------------------------------------

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    // ---- Roles ------------------------------------------------------------

    public function isGodMode(): bool
    {
        return $this->role === Role::GodMode;
    }

    public function atLeast(Role $role): bool
    {
        return $this->role instanceof Role && $this->role->atLeast($role);
    }

    // ---- Two-factor -------------------------------------------------------

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    /**
     * Feature 8: 2FA is mandatory for god mode. Enforced by middleware, which
     * is why this is a question about the user and not about the current page.
     */
    public function requiresTwoFactor(): bool
    {
        return $this->isGodMode();
    }

    // ---- Tier limits (Feature 8, enforced server-side) ---------------------

    /** god_mode bypasses tier limits — but never the protected gate. */
    public function withinLimit(string $limit, int $current): bool
    {
        if ($this->isGodMode()) {
            return true;
        }

        return $this->plan?->allows($limit, $current) ?? false;
    }

    public function siteCount(): int
    {
        return $this->sites()->managed()->count();
    }
}
