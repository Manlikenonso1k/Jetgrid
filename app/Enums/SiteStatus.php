<?php

namespace App\Enums;

enum SiteStatus: string
{
    case Unknown     = 'unknown';
    case Healthy     = 'healthy';
    case Degraded    = 'degraded';
    case Down        = 'down';
    case Deploying   = 'deploying';
    case Maintenance = 'maintenance';
}
