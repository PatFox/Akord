<?php

namespace App\Actions\Agents;

use App\Exceptions\ClaimCodeUnavailableException;
use App\Models\AgentClaimCode;
use App\Models\Party;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Exchanges a claim code for a Sanctum token for the agent party. The token has no abilities
 * of its own: requests get the party's (AgentActor). Each code works once
 * (the row is locked, so two simultaneous claims cannot both succeed) and expires.
 *
 * Errors: unknown code 404; used or expired 410.
 */
final class ClaimAgentToken
{
    /**
     * @return array{party: Party, token: string, abilities: list<string>}
     */
    public function handle(string $code, string $tokenName): array
    {
        return DB::transaction(function () use ($code, $tokenName): array {
            $claimCode = AgentClaimCode::query()
                ->where('code_hash', AgentClaimCode::hashCode($code))
                ->lockForUpdate()
                ->first()
                ?? throw (new ModelNotFoundException)->setModel(AgentClaimCode::class);

            if ($claimCode->claimed_at !== null) {
                throw new ClaimCodeUnavailableException('This claim code has already been used. Ask an owner for a new one.');
            }

            if ($claimCode->expires_at->isPast()) {
                throw new ClaimCodeUnavailableException('This claim code has expired. Ask an owner for a new one.');
            }

            $party = Party::query()->findOrFail($claimCode->party_id);
            $token = $party->createToken($tokenName);
            $claimCode->update(['claimed_at' => now()]);

            return ['party' => $party, 'token' => $token->plainTextToken, 'abilities' => $party->agentAbilities()];
        });
    }
}
