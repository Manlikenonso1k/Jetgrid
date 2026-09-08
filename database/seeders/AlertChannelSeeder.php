<?php

namespace Database\Seeders;

use App\Models\AlertChannel;
use Illuminate\Database\Seeder;

/**
 * Feature 6 — the pluggable alert channels, seeded inactive.
 *
 * They are created disabled and unconfigured on purpose: an install that starts
 * emailing on first boot is an install nobody trusts.
 */
class AlertChannelSeeder extends Seeder
{
    public function run(): void
    {
        $channels = [
            [
                'name' => 'Email',
                'type' => 'mail',
                'config' => ['to' => null],
            ],
            [
                'name' => 'Telegram',
                'type' => 'telegram',
                'config' => ['bot_token' => null, 'chat_id' => null],
            ],
            [
                'name' => 'Webhook',
                'type' => 'webhook',
                'config' => ['url' => null, 'secret' => null],
            ],
        ];

        foreach ($channels as $channel) {
            AlertChannel::firstOrCreate(
                ['type' => $channel['type']],
                $channel + ['is_active' => false],
            );
        }
    }
}
