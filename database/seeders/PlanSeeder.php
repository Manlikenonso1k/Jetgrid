<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            [
                'slug' => 'free', 'name' => 'Free', 'price_cents' => 0, 'sort_order' => 1,
                'max_sites' => 1, 'max_databases' => 1, 'max_storage_mb' => 1024,
                'max_backups' => 1, 'monitor_interval_seconds' => 900,
            ],
            [
                'slug' => 'starter', 'name' => 'Starter', 'price_cents' => 900, 'sort_order' => 2,
                'max_sites' => 5, 'max_databases' => 5, 'max_storage_mb' => 10240,
                'max_backups' => 7, 'monitor_interval_seconds' => 300,
            ],
            [
                'slug' => 'pro', 'name' => 'Pro', 'price_cents' => 2900, 'sort_order' => 3,
                'max_sites' => 25, 'max_databases' => 25, 'max_storage_mb' => 51200,
                'max_backups' => 30, 'monitor_interval_seconds' => 60,
            ],
            [
                // null = unlimited.
                'slug' => 'unlimited', 'name' => 'Unlimited', 'price_cents' => 7900, 'sort_order' => 4,
                'max_sites' => null, 'max_databases' => null, 'max_storage_mb' => null,
                'max_backups' => null, 'monitor_interval_seconds' => 30,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::updateOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
