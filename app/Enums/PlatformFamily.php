<?php

namespace App\Enums;

/**
 * Which PlatformCommands implementation is in play.
 *
 * Unknown is a real state, not a fallback: an OS JetGrid does not recognise gets
 * TCP and HTTP probing only, and every command-backed capability reports itself
 * as unavailable rather than guessing at a syntax.
 */
enum PlatformFamily: string
{
    case Windows = 'windows';
    case MacOS = 'macos';
    case Linux = 'linux';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Windows => 'Windows',
            self::MacOS => 'macOS',
            self::Linux => 'Linux',
            self::Unknown => 'Unknown',
        };
    }
}
