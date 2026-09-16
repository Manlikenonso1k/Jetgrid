<?php

namespace App\Services\Alerts;

use App\Jobs\SendTelegramAlert;
use App\Models\Site;

/**
 * Builds Telegram messages and hands them to the queue.
 *
 * Every interpolated value is escaped here rather than at the call sites, so a
 * domain or a WHOIS status containing a "<" cannot break parse_mode=HTML and
 * silently lose the alert.
 */
class TelegramNotifier
{
    public function enabled(): bool
    {
        return (bool) config('jetgrid.telegram.enabled')
            && (bool) config('jetgrid.telegram.bot_token')
            && (bool) config('jetgrid.telegram.chat_id');
    }

    public function send(string $message, ?Site $site = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        SendTelegramAlert::dispatch($message, $this->chatIdFor($site));
    }

    /**
     * Per-site override first, global second — so one install can alert several
     * clients' groups without a second bot.
     */
    public function chatIdFor(?Site $site): string
    {
        $override = is_array($site?->meta) ? ($site->meta['telegram_chat_id'] ?? null) : null;

        return (string) ($override ?: config('jetgrid.telegram.chat_id'));
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string,string> $rows */
    public function compose(string $icon, string $title, array $rows, ?string $footer = null): string
    {
        $message = "{$icon} <b>".self::escape($title).'</b>';

        foreach ($rows as $label => $value) {
            $message .= "\n<b>".self::escape($label).':</b> '.self::escape($value);
        }

        if ($footer !== null) {
            $message .= "\n\n<i>".self::escape($footer).'</i>';
        }

        return $message;
    }
}
