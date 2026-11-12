<?php

namespace App\Enums;

enum PartyType: string
{
    case Individual = 'individual';
    case Team = 'team';
    case Agent = 'agent';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
