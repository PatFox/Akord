<?php

namespace App\Enums;

enum ContractState: string
{
    case Draft = 'draft';
    case ReadyForSignoff = 'ready_for_signoff';
    case Agreed = 'agreed';
}
