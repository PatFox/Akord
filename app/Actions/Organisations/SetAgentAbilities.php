<?php

namespace App\Actions\Organisations;

use App\Models\AgentConnection;
use App\Models\Party;
use App\Models\User;
use App\Support\Agents\AgentAbilities;
use App\Support\OrganisationAccess;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Owner-only: changes what one of an agent's credentials may do, an MCP connection (OAuth)
 * or an API token (claim code). Takes effect on the agent's next request; 'read' is always kept.
 */
final class SetAgentAbilities
{
    /**
     * @param  list<string>  $abilities
     */
    public function handle(User $owner, Party $agent, AgentConnection|PersonalAccessToken $credential, array $abilities): void
    {
        OrganisationAccess::ensureOwner($owner, $agent->organisation_id);

        $belongs = $credential instanceof AgentConnection
            ? $credential->party_id === $agent->id && $credential->revoked_at === null
            : $credential->tokenable_type === $agent->getMorphClass() && (int) $credential->tokenable_id === $agent->id;
        abort_unless($belongs, 404);

        $credential->forceFill(['abilities' => AgentAbilities::normalise($abilities)])->save();
    }
}
