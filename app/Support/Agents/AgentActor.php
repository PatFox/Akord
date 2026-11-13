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
 */
final class AgentActor
{
    /**
     * @throws AuthorizationException when the caller is not an agent credential
     */
    public static function from(?Authenticatable $user): Actor
    {
        if ($user instanceof Party && $user->isAgent()) {
            /** @var PersonalAccessToken|null $token set by Sanctum for token-authenticated requests */
            $token = $user->currentAccessToken();
            $abilities = $token === null ? [] : array_values(array_map(strval(...), (array) $token->abilities));

            return Actor::agent($user, $abilities);
        }

        if ($user instanceof User && $user->token() instanceof AccessToken) {
            return self::fromConnection($user, $user->token());
        }

        throw new AuthorizationException('This API is for agent tokens.');
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
    private static function fromConnection(User $user, AccessToken $token): Actor
    {
        if (! $token->can(Registrar::OAUTH_SCOPE)) {
            throw new AuthorizationException('This token was not issued for the MCP server.');
        }

        $connection = AgentConnection::query()
            ->active()
            ->with('party')
            ->where('oauth_client_id', $token->oauth_client_id)
            ->where('approved_by', $user->getKey())
            ->latest('id')
            ->first();

        $party = $connection?->party;

        // The approver must still own the agent party's organisation.
        if ($connection === null || $party === null || ! $party->isAgent() || ! $user->isOwnerOf($party->organisation_id)) {
            throw new AuthorizationException('This connection is not approved for an agent party, or was revoked.');
        }

        if ($connection->last_used_at === null || $connection->last_used_at->lt(now()->subMinute())) {
            $connection->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return Actor::agent($party, $connection->abilities);
    }
}
