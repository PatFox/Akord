<?php

namespace App\Http\Controllers\Agents;

use App\Models\AgentConnection;
use App\Support\Agents\LoopbackRedirect;
use App\Support\Agents\PartyMcpUrl;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Laravel\Passport\Client;
use Laravel\Passport\Contracts\AuthorizationViewResponse;
use Laravel\Passport\Http\Controllers\AuthorizationController as PassportAuthorizationController;
use Laravel\Passport\Passport;
use Laravel\Passport\Scope;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passport's authorize endpoint, bound in its place (AppServiceProvider). Two changes:
 *
 * - Passport skips the approval screen when the client already holds a valid token for the user. That is only right
 *   when this client is already connected for the party being signed in for: a client reused for a second party's
 *   URL must see the screen, or no connection would be recorded.
 * - A local client returning on another loopback port is accepted (LoopbackRedirect).
 */
class AuthorizationController extends PassportAuthorizationController
{
    public function authorize(ServerRequestInterface $psrRequest, Request $request, ResponseInterface $psrResponse, AuthorizationViewResponse $viewResponse): Response|AuthorizationViewResponse
    {
        $clientId = $request->query('client_id');
        $redirectUri = $request->query('redirect_uri');
        if (is_string($clientId) && is_string($redirectUri)) {
            $client = Passport::client()->newQuery()->find($clientId);
            if ($client instanceof Client && ! $client->revoked) {
                LoopbackRedirect::allowPortChange($client, $redirectUri);
            }
        }

        return parent::authorize($psrRequest, $request, $psrResponse, $viewResponse);
    }

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
