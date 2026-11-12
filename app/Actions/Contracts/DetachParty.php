<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\Party;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Owner-only (D4): removes a party from a contract. Its past approvals and comments stay in history; its members lose access unless another of their parties is attached.
 */
final class DetachParty
{
    public function handle(Actor $actor, Contract $contract, Party $party): void
    {
        ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($party): void {
            $membership = ContractAccess::membership($party, $contract);

            if ($membership === null) {
                return;
            }

            if ($membership->is_required_approver) {
                throw new InvalidActionException('Remove the party as a required approver before detaching it.');
            }

            $membership->delete();
            $log->record('party.detached', $party, ['role' => $membership->role->value]);
        });
    }
}
