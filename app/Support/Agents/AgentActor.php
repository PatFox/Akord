<?php

namespace App\Support\Agents;

use App\Models\AgentConnection;
use App\Models\Party;
use App\Models\User;
use App\Support\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\AccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The actor behind an API or MCP request, from either kind of agent credential:
 *
 * - a Sanctum token from a claim code: the token's user is the agent party;
 * - an OAuth token (Passport) from MCP sign-in: the token's user is the owner who
 *   approved the client, and their AgentConnection names the agent party and abilities.
 *
 * At a party's own MCP URL (/mcp/agents/{party}) the credential must be for that party:
 * a repo configured for one party can never act as another.
 */
final class AgentActor
{
    /**
     * @param  int|null  $urlPartyId  the party named by the URL, if any
     *
     * @throws AuthorizationException when the caller is not an agent credential, or not one for the URL's party
     */
    public static function from(?Authenticatable $user, ?int $urlPartyId = null): Actor
    {
        if ($user instanceof Party && $user->isAgent()) {
            if ($urlPartyId !== null && $user->id !== $urlPartyId) {
                throw new AuthorizationException('This token belongs to another agent party. Use that party’s own MCP URL.');
            }

            /** @var PersonalAccessToken|null $token set by Sanctum for token-authenticated requests */
            $token = $user->currentAccessToken();
            $abilities = $token === null ? [] : array_values(array_map(strval(...), (array) $token->abilities));

            return Actor::agent($user, $abilities);
        }

        if ($user instanceof User && $user->token() instanceof AccessToken) {
            return self::fromConnection($user, $user->token(), $urlPartyId);
        }

        throw new AuthorizationException('This API is for agent tokens.');
    }

    /**
     * The agent party named by the current route (/mcp/agents/{party}), if any.
     */
    public static function urlPartyId(): ?int
    {
        $party = request()->route('party');

        return is_string($party) && ctype_digit($party) ? (int) $party : null;
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
    private static function fromConnection(User $user, AccessToken $token, ?int $urlPartyId): Actor
    {
        if (! $token->can(Registrar::OAUTH_SCOPE)) {
            throw new AuthorizationException('This token was not issued for the MCP server.');
        }

        // At a party's URL, only a connection approved for that party; elsewhere, the latest one.
        $connection = AgentConnection::query()
            ->active()
            ->with('party')
            ->where('oauth_client_id', $token->oauth_client_id)
            ->where('approved_by', $user->getKey())
            ->when($urlPartyId !== null, fn ($query) => $query->where('party_id', $urlPartyId))
            ->latest('id')
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

        return Actor::agent($party, $connection->abilities);
    }
}
