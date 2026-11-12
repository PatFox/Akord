<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Enums\ContractRole;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Party;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Owner-only (D4): adds or removes a party from the required approvers. Cancels the contract's sign-offs.
 */
final class SetRequiredApprover
{
    public function handle(Actor $actor, Contract $contract, Party $party, bool $required): ContractParty
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($party, $required): ContractParty {
            $membership = ContractAccess::membership($party, $contract)
                ?? throw new InvalidActionException('Attach the party to the contract first.');

            if ($required && $membership->role === ContractRole::Viewer) {
                throw new InvalidActionException('Viewers cannot approve, so they cannot be required approvers.');
            }

            if ($membership->is_required_approver !== $required) {
                $membership->update(['is_required_approver' => $required]);
                $log->record('party.required_changed', $party, ['required' => $required]);
            }

            return $membership;
        });
    }
}
