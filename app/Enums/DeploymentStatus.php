<?php

namespace App\Enums;

enum DeploymentStatus: string
{
    case Queued     = 'queued';
    case Running    = 'running';
    case Succeeded  = 'succeeded';
    case Failed     = 'failed';
    case RolledBack = 'rolled_back';

    public function inProgress(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
