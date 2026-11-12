<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Withdraws the actor's party's sign-off. Only possible before the contract is agreed (423 after).
 */
final class WithdrawSignOff
{
    /**
     * @return int the number of sign-offs withdrawn
     */
    public function handle(Actor $actor, Contract $contract): int
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Approve, function (Contract $contract, ActivityLog $log) use ($actor): int {
            $withdrawn = ContractSignoff::query()
                ->active()
                ->where('contract_id', $contract->getKey())
                ->where('party_id', ContractAccess::actingParty($actor)->getKey())
                ->update(['withdrawn_at' => now()]);

            if ($withdrawn > 0) {
                $log->record('signoff.withdrawn');
            }

            return $withdrawn;
        });
    }
}
