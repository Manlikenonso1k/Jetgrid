<?php

/**
 * JetGrid local monitor — standalone.
 *
 * Deliberately not part of the Laravel application. It is a single file with no
 * Composer dependencies, no framework and no database, so that it keeps working
 * when the thing it is watching does not. If the AWS box is down this still
 * alerts, and if this machine is off the server-side monitoring is unaffected.
 *
 * Usage:
 *   php check.php [--config=config.json] [--test] [--verbose]
 */

declare(strict_types=1);

const DEFAULT_CONFIG = __DIR__.DIRECTORY_SEPARATOR.'config.json';
const STATE_FILE = __DIR__.DIRECTORY_SEPARATOR.'state.json';

$options = getopt('', ['config::', 'test', 'verbose']);
$configPath = $options['config'] ?? DEFAULT_CONFIG;
$verbose = isset($options['verbose']);

$config = loadConfig($configPath);
$state = loadState();

if (isset($options['test'])) {
    $sent = notify($config, "\u{2139} <b>[LOCAL] JetGrid local monitor</b>\nTest message from ".hostname().'.');
    echo $sent ? "Test message sent.\n" : "Test message FAILED — check bot_token and chat_id.\n";
    exit($sent ? 0 : 1);
}

$transitions = [];

foreach ($config['targets'] as $target) {
    $name = (string) $target['name'];
    $result = checkTarget($target, (int) ($config['timeout'] ?? 5));

    if ($verbose) {
        printf("%-28s %s%s\n", $name, $result['ok'] ? 'UP  ' : 'DOWN', $result['ok'] ? '' : ' — '.$result['error']);
    }

    $transition = applyState($state, $name, $result, (int) ($config['failures_before_alert'] ?? 2));

    if ($transition !== null) {
        $transitions[] = $transition;
    }
}

saveState($state);

foreach ($transitions as $transition) {
    notify($config, formatMessage($transition));
}

if ($verbose) {
    echo count($transitions)." alert(s) sent.\n";
}

exit(0);

// ---------------------------------------------------------------------------

function loadConfig(string $path): array
{
    if (! is_file($path)) {
        fwrite(STDERR, "Config not found: {$path}\nCopy config.example.json to config.json and edit it.\n");
        exit(1);
    }

    $config = json_decode((string) file_get_contents($path), true);

    if (! is_array($config) || ! isset($config['targets']) || ! is_array($config['targets'])) {
        fwrite(STDERR, "Config is not valid JSON, or has no 'targets' array.\n");
        exit(1);
    }

    return $config;
}

function loadState(): array
{
    if (! is_file(STATE_FILE)) {
        return [];
    }

    $state = json_decode((string) file_get_contents(STATE_FILE), true);

    return is_array($state) ? $state : [];
}

function saveState(array $state): void
{
    @file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

/** @return array{ok:bool,error:?string,detail:string} */
function checkTarget(array $target, int $timeout): array
{
    if (isset($target['url'])) {
        return checkHttp((string) $target['url'], $timeout, $target['expect_status'] ?? null);
    }

    if (isset($target['host'], $target['port'])) {
        return checkPort((string) $target['host'], (int) $target['port'], $timeout);
    }

    return ['ok' => false, 'error' => 'target has neither url nor host+port', 'detail' => ''];
}

function checkHttp(string $url, int $timeout, ?int $expect): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $start = microtime(true);
    $body = @file_get_contents($url, false, $context);
    $ms = (int) round((microtime(true) - $start) * 1000);

    if ($body === false && ! isset($http_response_header)) {
        return ['ok' => false, 'error' => 'no response', 'detail' => "{$ms}ms"];
    }

    $status = 0;

    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m) === 1) {
            $status = (int) $m[1];
        }
    }

    $ok = $expect !== null ? $status === $expect : ($status > 0 && $status < 400);

    return [
        'ok' => $ok,
        'error' => $ok ? null : "HTTP {$status}",
        'detail' => "HTTP {$status} in {$ms}ms",
    ];
}

function checkPort(string $host, int $port, int $timeout): array
{
    $start = microtime(true);
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    $ms = (int) round((microtime(true) - $start) * 1000);

    if ($socket === false) {
        return ['ok' => false, 'error' => $errstr !== '' ? $errstr : "errno {$errno}", 'detail' => "{$ms}ms"];
    }

    fclose($socket);

    return ['ok' => true, 'error' => null, 'detail' => "connected in {$ms}ms"];
}

/**
 * Same discipline as the server side: alert on transitions only, and require N
 * consecutive failures before believing one.
 */
function applyState(array &$state, string $name, array $result, int $threshold): ?array
{
    $previous = $state[$name] ?? ['state' => 'unknown', 'failures' => 0, 'since' => null, 'alerted' => false];

    if ($result['ok']) {
        $wasAlerted = (bool) $previous['alerted'];
        $downSince = $previous['since'];

        $state[$name] = ['state' => 'ok', 'failures' => 0, 'since' => null, 'alerted' => false];

        if (! $wasAlerted) {
            return null;
        }

        return [
            'name' => $name,
            'recovered' => true,
            'detail' => $result['detail'],
            'since' => $downSince,
        ];
    }

    $failures = ($previous['state'] === 'failing' ? (int) $previous['failures'] : 0) + 1;
    $since = $previous['state'] === 'failing' && $previous['since'] ? $previous['since'] : date(DATE_ATOM);
    $alerted = (bool) $previous['alerted'];

    $state[$name] = ['state' => 'failing', 'failures' => $failures, 'since' => $since, 'alerted' => $alerted];

    if ($failures < $threshold || $alerted) {
        return null;
    }

    $state[$name]['alerted'] = true;

    return [
        'name' => $name,
        'recovered' => false,
        'detail' => $result['error'] ?? 'unreachable',
        'since' => $since,
    ];
}

function formatMessage(array $transition): string
{
    $icon = $transition['recovered'] ? "\u{2705}" : "\u{1F534}";
    $title = $transition['recovered'] ? 'RECOVERED' : 'DOWN';

    $message = "{$icon} <b>[LOCAL] {$title}</b>"
        ."\n<b>Target:</b> ".esc($transition['name'])
        ."\n<b>Machine:</b> ".esc(hostname())
        ."\n<b>Detail:</b> ".esc($transition['detail']);

    if ($transition['since'] !== null) {
        $seconds = max(0, time() - (int) strtotime((string) $transition['since']));
        $message .= "\n<b>".($transition['recovered'] ? 'Was down for' : 'Failing since').'</b> '
            .esc($transition['recovered'] ? humanDuration($seconds) : (string) $transition['since']);
    }

    return $message;
}

function humanDuration(int $seconds): string
{
    if ($seconds < 60) {
        return "{$seconds}s";
    }

    if ($seconds < 3600) {
        return intdiv($seconds, 60).'m';
    }

    return intdiv($seconds, 3600).'h '.intdiv($seconds % 3600, 60).'m';
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hostname(): string
{
    return gethostname() ?: 'unknown-host';
}

function notify(array $config, string $message): bool
{
    $token = $config['bot_token'] ?? '';
    $chatId = $config['chat_id'] ?? '';

    if ($token === '' || $chatId === '') {
        fwrite(STDERR, "bot_token or chat_id missing from config.\n");

        return false;
    }

    $payload = http_build_query([
        'chat_id' => $chatId,
        'text' => $message,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => 'true',
    ]);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
            'timeout' => 10,
            'ignore_errors' => true,
        ],
    ]);

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    // Two attempts, then give up quietly. A failed alert must not crash the
    // scheduled task, or Windows will eventually stop running it.
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $response = @file_get_contents($url, false, $context);

        if ($response !== false && str_contains((string) $response, '"ok":true')) {
            return true;
        }

        if ($attempt === 1) {
            usleep(200_000);
        }
    }

    fwrite(STDERR, "Telegram delivery failed for: {$message}\n");

    return false;
}
