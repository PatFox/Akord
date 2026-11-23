<?php

namespace App\Support\Agents;

use App\Models\AgentConnection;
use App\Models\Party;
use App\Models\User;
use App\Support\Actor;
use App\Support\RandomId;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;

/**
 * The actor behind an API or MCP request, from either kind of agent credential:
 *
 * - a Sanctum token from a claim code: the token's user is the agent party;
 * - an OAuth token (Passport) from MCP sign-in: the token's user is the owner who
 *   approved the client, and their AgentConnection names the agent party.
 *
 * Either way the actor gets the party's abilities (Party::agentAbilities()), read on every request.
 * At a party's own MCP URL (/mcp/agents/{party}) the credential must be for that party:
 * a repo configured for one party can never act as another.
 */
final class AgentActor
{
    /**
     * @param  string|null  $urlPartyId  the party named by the URL, if any
     *
     * @throws AuthorizationException when the caller is not an agent credential, or not one for the URL's party
     */
    public static function from(?Authenticatable $user, ?string $urlPartyId = null): Actor
    {
        if ($user instanceof Party && $user->isAgent()) {
            if ($urlPartyId !== null && $user->id !== $urlPartyId) {
                throw new AuthorizationException('This token belongs to another agent party. Use that party’s own MCP URL.');
            }

            return Actor::agent($user, $user->agentAbilities());
        }

        if ($user instanceof User && $user->token() instanceof AccessToken) {
            return self::fromConnection($user, $user->token(), $urlPartyId);
        }

        throw new AuthorizationException('This API is for agent tokens.');
    }

    /**
     * The agent party named by the current route (/mcp/agents/{party}), if any.
     */
    public static function urlPartyId(): ?string
    {
        $party = request()->route('party');

        return RandomId::isValid($party) ? $party : null;
    }

    /**
     * The approved connection for this OAuth client, for the key the rate limiter uses.
     */
    public static function connectionKey(?Authenticatable $user): ?string
    {
        return match (true) {
            $user instanceof Party => 'party:'.$user->getKey(),
            $user instanceof User && $user->token() instanceof AccessToken => 'oauth-client:'.$user->token()->oauth_client_id,
            default => null,
        };
    }

    /**
     * @param  AccessToken<mixed>  $token
     */
    private static function fromConnection(User $user, AccessToken $token, ?string $urlPartyId): Actor
    {
        if (! $token->can(Registrar::OAUTH_SCOPE)) {
            throw new AuthorizationException('This token was not issued for the MCP server.');
        }

        // At a party's URL, only a connection approved for that party; elsewhere, the latest one.
        // Approving again creates a new connection (ConnectAgentController), so latest = newest row.
        $connection = AgentConnection::query()
            ->active()
            ->with('party')
            ->where('oauth_client_id', $token->oauth_client_id)
            ->where('approved_by', $user->getKey())
            ->when($urlPartyId !== null, fn ($query) => $query->where('party_id', $urlPartyId))
            ->latest('created_at')
            ->orderByDesc('id')
            ->first();

        $party = $connection?->party;

        // The approver must still own the agent party's organisation.
        if ($connection === null || $party === null || ! $party->isAgent() || ! $user->isOwnerOf($party->organisation_id)) {
            throw new AuthorizationException($urlPartyId === null
                ? 'This connection is not approved for an agent party, or was revoked.'
                : 'This sign-in is not approved for the agent party at this URL, or was revoked. Sign in again from this URL and choose that party.');
        }

        if ($connection->last_used_at === null || $connection->last_used_at->lt(now()->subMinute())) {
            $connection->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return Actor::agent($party, $party->agentAbilities());
    }
}
