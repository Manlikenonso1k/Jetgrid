<?php

namespace App\Enums;

/** Why a project cannot start yet. Drives the yellow beacon (L3). */
enum InstallState: string
{
    case Ready = 'ready';
    case DependenciesMissing = 'dependencies_missing';
    case NotConfigured = 'not_configured';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::DependenciesMissing => 'Dependencies missing',
            self::NotConfigured => 'Not configured',
        };
    }
}
