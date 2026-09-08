<?php

namespace App\Services\Alerts;

use App\Models\AlertChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Feature 6 — pluggable alert channels.
 *
 * Adding a channel means adding one method and one `match` arm. A channel that
 * throws is logged and skipped rather than aborting the rest: a broken Telegram
 * token must not stop the email about an expiring certificate.
 */
class AlertDispatcher
{
    public function send(string $subject, string $body, string $severity = 'info'): void
    {
        $channels = AlertChannel::active()->get();

        if ($channels->isEmpty()) {
            Log::warning("[jetgrid] {$subject} — no active alert channel configured.", ['body' => $body]);

            return;
        }

        foreach ($channels as $channel) {
            try {
                match ($channel->type) {
                    'mail' => $this->mail($channel, $subject, $body),
                    'telegram' => $this->telegram($channel, $subject, $body, $severity),
                    'webhook' => $this->webhook($channel, $subject, $body, $severity),
                    default => Log::warning("[jetgrid] Unknown alert channel type: {$channel->type}"),
                };

                $channel->forceFill(['last_dispatched_at' => now()])->save();
            } catch (Throwable $e) {
                Log::error("[jetgrid] Alert channel {$channel->name} failed: ".$e->getMessage());
            }
        }
    }

    private function mail(AlertChannel $channel, string $subject, string $body): void
    {
        $to = $channel->config['to'] ?? null;

        if (! $to) {
            throw new \RuntimeException('Mail channel has no recipient configured.');
        }

        Mail::raw($body, fn ($message) => $message->to($to)->subject("[JetGrid] {$subject}"));
    }

    private function telegram(AlertChannel $channel, string $subject, string $body, string $severity): void
    {
        $token = $channel->config['bot_token'] ?? null;
        $chatId = $channel->config['chat_id'] ?? null;

        if (! $token || ! $chatId) {
            throw new \RuntimeException('Telegram channel is missing bot_token or chat_id.');
        }

        $icon = match ($severity) {
            'critical' => "\u{1F534}",
            'warning' => "\u{1F7E1}",
            default => "\u{1F7E2}",
        };

        Http::timeout(10)
            ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                'chat_id' => $chatId,
                'text' => "{$icon} *{$subject}*\n\n{$body}",
                'parse_mode' => 'Markdown',
            ])
            ->throw();
    }

    private function webhook(AlertChannel $channel, string $subject, string $body, string $severity): void
    {
        $url = $channel->config['url'] ?? null;

        if (! $url) {
            throw new \RuntimeException('Webhook channel has no URL configured.');
        }

        $payload = [
            'source' => 'jetgrid',
            'severity' => $severity,
            'subject' => $subject,
            'body' => $body,
            'sent_at' => now()->toIso8601String(),
        ];

        $request = Http::timeout(10);

        // Sign the payload so the receiver can tell it came from this install.
        if ($secret = $channel->config['secret'] ?? null) {
            $request = $request->withHeaders([
                'X-JetGrid-Signature' => hash_hmac('sha256', json_encode($payload), $secret),
            ]);
        }

        $request->post($url, $payload)->throw();
    }
}
