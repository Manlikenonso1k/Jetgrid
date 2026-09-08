<?php

namespace App\Enums;

/** Rooftop strobe colour on the 3D grid. Blink rate encodes urgency. */
enum BeaconColor: string
{
    case Green  = 'green';   // healthy
    case Red    = 'red';     // down / erroring / overloaded
    case Yellow = 'yellow';  // updates pending or cert expiring soon
    case Blue   = 'blue';    // deployment in progress
    case Grey   = 'grey';    // protected / adopted, monitoring only

    /** Blinks per second. Faster = more critical. */
    public function blinkHz(): float
    {
        return match ($this) {
            self::Red    => 2.4,
            self::Blue   => 1.6,
            self::Yellow => 1.0,
            self::Green  => 0.4,
            self::Grey   => 0.2,
        };
    }

    public function hex(): string
    {
        return match ($this) {
            self::Green  => '#39FF14',
            self::Red    => '#FF3B30',
            self::Yellow => '#FFD60A',
            self::Blue   => '#3B9DFF',
            self::Grey   => '#8A8F98',
        };
    }
}
