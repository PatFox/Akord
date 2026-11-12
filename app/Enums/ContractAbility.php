<?php

namespace App\Enums;

enum ContractAbility
{
    case View;
    case Edit;
    case Comment;
    case Approve;
    case Administer;

    /**
     * The API token ability an agent needs for this.
     */
    public function tokenAbility(): string
    {
        return match ($this) {
            self::View => 'read',
            self::Edit => 'edit',
            self::Comment => 'comment',
            self::Approve => 'approve',
            self::Administer => 'administer',
        };
    }
}
