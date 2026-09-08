<?php

namespace App\Enums;

/**
 * Which layer of L5 produced a run-state answer.
 *
 * This is surfaced everywhere the state is, including `jetgrid:scan`, because
 * "port 8000 is open" and "PID 20456, cwd matches this project" are very
 * different claims and the operator needs to see which one they are being given.
 */
enum DetectionLayer: string
{
    case None = 'none';
    case Tcp = 'tcp';
    case Http = 'http';
    case Process = 'process';
    case Docker = 'docker';

    public function label(): string
    {
        return match ($this) {
            self::None => 'none (no port open)',
            self::Tcp => 'L1 tcp',
            self::Http => 'L2 http',
            self::Process => 'L3 process',
            self::Docker => 'L4 docker',
        };
    }
}
