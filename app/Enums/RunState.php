<?php

namespace App\Enums;

/** Run state of a discovered local project. Maps onto the L7 beacon colours. */
enum RunState: string
{
    case Stopped = 'stopped';
    case Starting = 'starting';
    case Running = 'running';
    case Erroring = 'erroring';
    case Conflict = 'conflict';
    case Docker = 'docker';
    case Failed = 'failed';

    public function isUp(): bool
    {
        return in_array($this, [self::Running, self::Erroring, self::Docker], true);
    }

    public function beacon(): BeaconColor
    {
        return match ($this) {
            self::Running => BeaconColor::Green,
            self::Starting => BeaconColor::Blue,
            self::Stopped => BeaconColor::Grey,
            self::Erroring, self::Conflict, self::Failed => BeaconColor::Red,
            self::Docker => BeaconColor::Purple,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Stopped => 'Not running',
            self::Starting => 'Starting',
            self::Running => 'Running',
            self::Erroring => 'Running, erroring',
            self::Conflict => 'Port held by another process',
            self::Docker => 'Running under Docker',
            self::Failed => 'Start failed',
        };
    }
}
