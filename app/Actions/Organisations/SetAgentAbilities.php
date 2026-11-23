<?php

namespace App\Actions\Organisations;

use App\Exceptions\InvalidActionException;
use App\Models\Party;
use App\Models\User;
use App\Support\Agents\AgentAbilities;
use App\Support\OrganisationAccess;

/**
 * Owner-only: sets what an agent party may do, on top of its role on each contract. Applies to
 * every credential of the party (MCP sign-ins and claim-code tokens) from its next request; 'read' is always kept.
 */
final class SetAgentAbilities
{
    /**
     * @param  array<int, mixed>  $abilities
     */
    public function handle(User $owner, Party $agent, array $abilities): void
    {
        OrganisationAccess::ensureOwner($owner, $agent->organisation_id);

        if (! $agent->isAgent()) {
            throw new InvalidActionException('Only agent parties have abilities; people act through their role on each contract.');
        }

        $agent->forceFill(['agent_abilities' => AgentAbilities::normalise($abilities)])->save();
    }
}
