<?php

namespace App\Support\Agents;

use App\Models\Party;
use App\Support\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The actor behind an API or MCP request: an agent party and its token's abilities.
 */
final class AgentActor
{
    /**
     * @throws AuthorizationException when the caller is not an agent token
     */
    public static function from(?Authenticatable $user): Actor
    {
        if (! $user instanceof Party || ! $user->isAgent()) {
            throw new AuthorizationException('This API is for agent tokens.');
        }

        /** @var PersonalAccessToken|null $token set by Sanctum for token-authenticated requests */
        $token = $user->currentAccessToken();
        $abilities = $token === null ? [] : array_values(array_map(strval(...), (array) $token->abilities));

        return Actor::agent($user, $abilities);
    }
}
