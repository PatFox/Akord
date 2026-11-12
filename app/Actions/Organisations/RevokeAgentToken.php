<?php

namespace App\Actions\Organisations;

use App\Models\Party;
use App\Models\User;
use App\Support\OrganisationAccess;

/**
 * Owner-only: revokes one of an agent's API tokens; its next request gets 401.
 */
final class RevokeAgentToken
{
    public function handle(User $owner, Party $agent, int $tokenId): void
    {
        OrganisationAccess::ensureOwner($owner, $agent->organisation_id);

        $agent->tokens()->whereKey($tokenId)->firstOrFail()->delete();
    }
}
