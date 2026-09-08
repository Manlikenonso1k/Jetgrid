<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime settings a god_mode user can change without editing .env.
 *
 * The kill switch is the important one, and how these rows combine with .env is
 * deliberately fail-closed — that logic lives in App\Support\KillSwitch, not
 * here, so a missing or corrupt settings table can never turn writes on.
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember("jetgrid.setting.{$key}", 30, function () use ($key, $default) {
            return static::find($key)?->value ?? $default;
        });
    }

    public static function put(string $key, mixed $value, ?User $by = null): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'updated_by' => $by?->id],
        );

        Cache::forget("jetgrid.setting.{$key}");
    }

    public static function flag(string $key, bool $default = false): bool
    {
        return (bool) static::get($key, $default ? '1' : '0');
    }
}
