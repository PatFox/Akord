<?php

namespace App\Enums;

enum ContractState: string
{
    case Draft = 'draft';
    case ReadyForSignoff = 'ready_for_signoff';
    case Agreed = 'agreed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::ReadyForSignoff => 'Ready for sign-off',
            self::Agreed => 'Agreed',
        };
    }

    /**
     * Flux badge colour.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::ReadyForSignoff => 'amber',
            self::Agreed => 'green',
        };
    }
}
