<?php

namespace App\Actions\Organisations;

use App\Exceptions\InvalidActionException;
use App\Models\Party;
use App\Models\User;
use App\Support\OrganisationAccess;

/**
 * Owner-only: an owner joins or leaves one of their organisation's parties.
 */
final class SetOwnPartyMembership
{
    public function handle(User $user, Party $party, bool $member): void
    {
        OrganisationAccess::ensureOwner($user, $party->organisation_id);

        if ($party->isAgent()) {
            throw new InvalidActionException('Agent parties have no human members.');
        }

        $member
            ? $party->users()->syncWithoutDetaching([$user->id])
            : $party->users()->detach($user->id);
    }
}
