<?php

namespace App\Enums;

enum ContractRole: string
{
    case Editor = 'editor';
    case Commenter = 'commenter';
    case Viewer = 'viewer';

    public function rank(): int
    {
        return match ($this) {
            self::Editor => 3,
            self::Commenter => 2,
            self::Viewer => 1,
        };
    }

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
