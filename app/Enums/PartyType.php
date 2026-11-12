<?php

namespace App\Enums;

enum PartyType: string
{
    case Individual = 'individual';
    case Team = 'team';
    case Agent = 'agent';
}
