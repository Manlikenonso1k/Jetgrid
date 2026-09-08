<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Plan;
use App\Models\Setting;
use App\Models\User;
use App\Support\KillSwitch;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds the god_mode account and locks the kill switch on.
 *
 * The password is generated, printed once and never written anywhere else. An
 * install script that ships a known default password is how a control panel
 * with root-adjacent sudo rights ends up compromised on its first day.
 */
class GodModeSeeder extends Seeder
{
    public const GOD_EMAIL = 'victorynonso9@gmail.com';

    public function run(): void
    {
        // Safety constraint #8: read-only is the state a fresh install starts in.
        Setting::updateOrCreate(['key' => KillSwitch::SETTING], ['value' => '1']);

        $existing = User::firstWhere('email', self::GOD_EMAIL);

        if ($existing !== null) {
            // Never silently reset the password of an account that already
            // exists — re-running the seeder must not be a way to take it over.
            $existing->forceFill(['role' => Role::GodMode])->save();

            $this->command?->warn('God mode account already exists; role reasserted, password untouched.');

            return;
        }

        $password = Str::password(20);

        User::create([
            'name' => 'Victory',
            'email' => self::GOD_EMAIL,
            'password' => Hash::make($password),
            'role' => Role::GodMode,
            'plan_id' => Plan::firstWhere('slug', 'unlimited')?->id,
            'email_verified_at' => now(),
        ]);

        $this->command?->newLine();
        $this->command?->info('God mode account created');
        $this->command?->line('  email:    '.self::GOD_EMAIL);
        $this->command?->line('  password: '.$password);
        $this->command?->newLine();
        $this->command?->warn('Write this down now — it is not stored anywhere and will not be shown again.');
        $this->command?->warn('God mode requires 2FA: you will be sent to enrolment on first login.');
    }
}
