<?php

namespace App\Actions\Organisations;

use App\Exceptions\InvalidActionException;
use App\Models\AgentConnectionLink;
use App\Models\Party;
use App\Models\User;
use App\Support\OrganisationAccess;
use Illuminate\Support\Str;

/**
 * Owner-only: a one-time link the owner gives an agent, with which the agent fetches and saves
 * its party's MCP configuration. The agent then signs in through the browser as usual (OAuth).
 * Only the link's hash is stored; the link is shown once.
 */
final class CreateAgentConnectionLink
{
    /**
     * @return array{0: AgentConnectionLink, 1: string} the stored link and its URL, to show once
     */
    public function handle(User $owner, Party $agent): array
    {
        OrganisationAccess::ensureOwner($owner, $agent->organisation_id);

        if (! $agent->isAgent()) {
            throw new InvalidActionException('Only agent parties connect with a connection link; invite people by email.');
        }

        $token = Str::random(40);

        $link = AgentConnectionLink::query()->create([
            'party_id' => $agent->id,
            'token_hash' => AgentConnectionLink::hashToken($token),
            'created_by' => $owner->id,
            'expires_at' => now()->addHours((int) config('akord.agents.connection_link_hours')),
        ]);

        return [$link, route('agents.link.show', $token)];
    }
}
