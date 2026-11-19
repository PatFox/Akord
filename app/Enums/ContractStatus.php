<?php

namespace App\Enums;

/**
 * Set by people (D14), unlike the derived ContractState. Anything but Open locks the version.
 */
enum ContractStatus: string
{
    case Open = 'open';
    case Complete = 'complete';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Complete => 'Complete',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Flux badge colour.
     */
    public function colour(): string
    {
        return match ($this) {
            self::Open => 'blue',
            self::Complete => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
