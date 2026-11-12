<?php

namespace App\Actions\Organisations;

use App\Enums\PartyType;
use App\Exceptions\InvalidActionException;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\User;
use App\Support\OrganisationAccess;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only (D4): creates a party (individual, team or agent). Other people join through invitations (Phase 6).
 */
final class CreateParty
{
    public function handle(User $user, Organisation $organisation, string $name, PartyType $type, bool $joinAsMember = false): Party
    {
        OrganisationAccess::ensureOwner($user, $organisation->id);

        if ($joinAsMember && $type === PartyType::Agent) {
            throw new InvalidActionException('Agent parties have no human members.');
        }

        return DB::transaction(function () use ($user, $organisation, $name, $type, $joinAsMember): Party {
            $party = Party::query()->create(['organisation_id' => $organisation->id, 'name' => $name, 'type' => $type]);

            if ($joinAsMember) {
                $party->users()->attach($user);
            }

            return $party;
        });
    }
}
