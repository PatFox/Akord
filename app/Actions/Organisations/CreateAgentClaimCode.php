<?php

namespace App\Actions\Organisations;

use App\Exceptions\InvalidActionException;
use App\Models\AgentClaimCode;
use App\Models\Party;
use App\Models\User;
use App\Support\Agents\AgentAbilities;
use App\Support\OrganisationAccess;

/**
 * Owner-only: a one-time code an agent exchanges for an API token with the chosen abilities. Only the code's hash is stored; the code is shown once.
 */
final class CreateAgentClaimCode
{
    /** Unambiguous characters (no 0/O, 1/I/L, U): 5 bits each. */
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * @param  array<int, mixed>  $abilities  token abilities; 'read' is always included
     * @return array{0: AgentClaimCode, 1: string} the stored code and the code to show once
     */
    public function handle(User $owner, Party $agent, array $abilities): array
    {
        OrganisationAccess::ensureOwner($owner, $agent->organisation_id);

        if (! $agent->isAgent()) {
            throw new InvalidActionException('Only agent parties connect with a claim code; invite people by email.');
        }

        $raw = '';
        for ($i = 0; $i < 20; $i++) {
            $raw .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        $code = 'AKD-'.implode('-', str_split($raw, 5));

        $claimCode = AgentClaimCode::query()->create([
            'party_id' => $agent->id,
            'code_hash' => AgentClaimCode::hashCode($code),
            'abilities' => AgentAbilities::normalise($abilities),
            'created_by' => $owner->id,
            'expires_at' => now()->addHours((int) config('akord.agents.claim_code_hours')),
        ]);

        return [$claimCode, $code];
    }
}
