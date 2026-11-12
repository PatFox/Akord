<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Exceptions\StaleFingerprintException;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Signs off the whole contract for the actor's party (D8). The last required sign-off on the current fingerprint agrees and locks the contract. The contract row lock makes concurrent final sign-offs agree it exactly once.
 */
final class SignOffContract
{
    /**
     * @param  string  $fingerprint  the fingerprint of the contract state the party reviewed
     *
     * @throws AuthorizationException not a required approver, or an agent under D10 (403)
     * @throws StaleFingerprintException the contract changed since review (409)
     * @throws InvalidActionException not ready for sign-off (422)
     */
    public function handle(Actor $actor, Contract $contract, string $fingerprint): ContractSignoff
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Approve, function (Contract $contract, ActivityLog $log) use ($actor, $fingerprint): ContractSignoff {
            $party = ContractAccess::actingParty($actor);

            $membership = ContractAccess::membership($party, $contract);
            if ($membership === null || ! $membership->is_required_approver) {
                throw new AuthorizationException('Only required approver parties can sign off.');
            }

            if ($contract->require_human_signoff && $actor->isAgent()) {
                throw new AuthorizationException('This contract must be signed off by a person.');
            }

            $report = ContractReadiness::report($contract);

            if ($fingerprint !== $report->fingerprint) {
                throw new StaleFingerprintException;
            }

            if (! $report->isReady()) {
                throw new InvalidActionException('Every active step must be approved by every required party before sign-off.');
            }

            $signoff = ContractSignoff::query()
                ->active()
                ->where('contract_id', $contract->getKey())
                ->where('party_id', $party->getKey())
                ->where('fingerprint', $fingerprint)
                ->first();

            if ($signoff === null) {
                $signoff = ContractSignoff::query()->create([
                    'contract_id' => $contract->getKey(),
                    'party_id' => $party->getKey(),
                    'user_id' => $actor->user?->getKey(),
                    'fingerprint' => $fingerprint,
                ]);
                $log->record('signoff.created', $signoff, ['fingerprint' => $fingerprint]);
            }

            $signedOff = array_unique([...$report->signedOffPartyIds, (int) $party->getKey()]);

            if (array_diff($report->requiredPartyIds, $signedOff) === []) {
                $contract->forceFill(['agreed_at' => now(), 'agreed_fingerprint' => $fingerprint])->save();
                $log->record('contract.agreed', $contract, ['fingerprint' => $fingerprint]);
            }

            return $signoff;
        });
    }
}
