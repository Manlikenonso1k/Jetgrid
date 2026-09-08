<?php

namespace Database\Factories;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => Role::Viewer,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => ['email_verified_at' => null]);
    }

    public function godMode(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => Role::GodMode,
            // Seeded as already enrolled so tests are not redirected to the
            // 2FA page; RequireTwoFactor has its own dedicated test.
            'two_factor_secret' => 'TESTSECRETTESTSECRETTESTSECRET12',
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
