<?php

namespace App\Http\Controllers\Agents;

use App\Http\Controllers\Controller;
use App\Models\AgentConnection;
use App\Models\Party;
use App\Support\Agents\AgentAbilities;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Passport\Bridge\Client;
use Laravel\Passport\Bridge\Scope;
use Laravel\Passport\Bridge\User;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use League\OAuth2\Server\RequestTypes\AuthorizationRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Approves an MCP client's OAuth request as one of the owner's agent parties: records the
 * AgentConnection (client → party + abilities), then lets Passport issue the code.
 */
class ConnectAgentController extends Controller
{
    public function approve(Request $request): Response
    {
        $validated = $request->validate([
            'auth_token' => ['required', 'string'],
            'party_id' => ['required', 'integer'],
            'abilities' => ['array'],
            'abilities.*' => ['string', Rule::in(AgentAbilities::ALL)],
        ]);

        // Peek at Passport's pending request (Passport itself consumes it below).
        $serialized = $request->session()->get('authRequest');
        abort_if(! is_string($serialized) || $request->session()->get('authToken') !== $validated['auth_token'], 419, 'This sign-in request has expired. Start again from your MCP client.');
        $authRequest = unserialize($serialized, ['allowed_classes' => [
            AuthorizationRequest::class, Client::class, Scope::class, User::class,
        ]]);
        abort_unless($authRequest instanceof AuthorizationRequest, 419);

        $user = $request->user() ?? abort(401);
        $party = Party::query()->findOrFail((int) $validated['party_id']);
        if (! $party->isAgent() || ! $user->isOwnerOf($party->organisation_id)) {
            throw new AuthorizationException('Only owners can connect a tool to their organisation’s agent parties.');
        }

        $clientId = $authRequest->getClient()->getIdentifier();

        DB::transaction(function () use ($clientId, $user, $party, $validated): void {
            AgentConnection::query()->active()
                ->where('oauth_client_id', $clientId)->where('approved_by', $user->getKey())
                ->update(['revoked_at' => now()]);

            AgentConnection::query()->create([
                'party_id' => $party->id,
                'oauth_client_id' => $clientId,
                'approved_by' => $user->getKey(),
                'abilities' => AgentAbilities::normalise($validated['abilities'] ?? []),
            ]);
        });

        return app()->call([app(ApproveAuthorizationController::class), 'approve']);
    }
}
