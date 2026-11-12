<?php

namespace App\Support;

use App\Models\Organisation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class OrganisationAccess
{
    /**
     * Owners only. An organisation the user cannot see at all is "not found".
     *
     * @throws ModelNotFoundException<Organisation>
     * @throws AuthorizationException
     */
    public static function ensureOwner(User $user, int $organisationId): void
    {
        if ($user->isOwnerOf($organisationId)) {
            return;
        }

        if (! Organisation::query()->visibleTo($user)->whereKey($organisationId)->exists()) {
            throw (new ModelNotFoundException)->setModel(Organisation::class, [$organisationId]);
        }

        throw new AuthorizationException('Only organisation owners can do this.');
    }
}
