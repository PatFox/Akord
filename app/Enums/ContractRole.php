<?php

namespace App\Enums;

enum ContractRole: string
{
    case Editor = 'editor';
    case Commenter = 'commenter';
    case Viewer = 'viewer';
}
