<?php

namespace App\Http\Controllers\Agents;

use App\Models\AgentConnection;
use App\Support\Agents\PartyMcpUrl;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Scope;

/**
 * Passport's authorize endpoint, bound in its place (AppServiceProvider). Passport skips the
 * approval screen when the client already holds a valid token for the user. That is only
 * right when this client is already connected for the party being signed in for: a client
 * reused for a second party's URL must see the screen, or no connection would be recorded.
 */
class AuthorizationController extends PassportAuthorizationController
{
    /**
     * @param  Scope[]  $scopes
     */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        $partyId = PartyMcpUrl::partyIdFrom(request()->query('resource'));

        $connected = AgentConnection::query()->active()
            ->where('oauth_client_id', $client->getKey())
            ->where('approved_by', $user->getAuthIdentifier())
            ->when($partyId !== null, fn ($query) => $query->where('party_id', $partyId))
            ->exists();

        return $connected && parent::hasGrantedScopes($user, $client, $scopes);
    }
}
