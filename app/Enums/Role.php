<?php

namespace App\Enums;

enum Role: string
{
    case GodMode = 'god_mode';
    case Admin = 'admin';
    case Developer = 'developer';
    case Viewer = 'viewer';

    /** Higher wins. Used for "at least this role" checks. */
    public function rank(): int
    {
        return match ($this) {
            self::GodMode => 40,
            self::Admin => 30,
            self::Developer => 20,
            self::Viewer => 10,
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::GodMode => 'God Mode',
            self::Admin => 'Admin',
            self::Developer => 'Developer',
            self::Viewer => 'Viewer',
        };
    }
}
