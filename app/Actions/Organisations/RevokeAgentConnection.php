<?php

namespace App\Actions\Organisations;

use App\Models\AgentConnection;
use App\Models\Party;
use App\Models\User;
use App\Support\OrganisationAccess;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\RefreshToken;
use Laravel\Passport\Token;

/**
 * Owner-only: disconnects an MCP client (OAuth) from an agent party. Its access and refresh tokens are revoked, so the next call gets 401; reconnecting needs a new approval.
 */
final class RevokeAgentConnection
{
    public function handle(User $owner, Party $agent, AgentConnection $connection): void
    {
        OrganisationAccess::ensureOwner($owner, $agent->organisation_id);
        abort_unless($connection->party_id === $agent->id, 404);

        DB::transaction(function () use ($connection): void {
            $connection->update(['revoked_at' => now()]);

            $tokens = Token::query()->where('client_id', $connection->oauth_client_id)->where('user_id', $connection->approved_by);
            RefreshToken::query()->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
            $tokens->update(['revoked' => true]);
        });
    }
}
