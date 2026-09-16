<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivery only — every decision about whether an alert should exist was made
 * before this was dispatched.
 *
 * Nothing here throws. An unreachable Telegram API must not fail the scheduled
 * command that noticed the problem, or a network blip would take monitoring
 * down at exactly the moment it matters.
 */
class SendTelegramAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $message,
        private readonly string $chatId,
    ) {}

    public function handle(): void
    {
        $token = config('jetgrid.telegram.bot_token');

        if (! $token || ! $this->chatId) {
            Log::warning('[jetgrid] Telegram alert skipped: bot token or chat id missing.');

            return;
        }

        $base = rtrim((string) config('jetgrid.telegram.api_base'), '/');

        try {
            $response = Http::timeout((int) config('jetgrid.telegram.timeout', 10))
                ->retry(2, 200, throw: false)
                ->post("{$base}/bot{$token}/sendMessage", [
                    'chat_id' => $this->chatId,
                    'text' => $this->message,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ]);

            if (! $response->successful()) {
                Log::error('[jetgrid] Telegram alert rejected.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (Throwable $e) {
            Log::error('[jetgrid] Telegram alert failed: '.$e->getMessage());
        }
    }
}
