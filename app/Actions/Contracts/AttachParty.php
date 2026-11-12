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
 * Owner-only (D4): attaches a party of the contract's organisation to the contract, or changes its role.
 */
final class AttachParty
{
    public function handle(Actor $actor, Contract $contract, Party $party, ContractRole $role): ContractParty
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($party, $role): ContractParty {
            // A party from another organisation is reported as missing, not forbidden.
            $party = Party::query()->whereKey($party->id)->where('organisation_id', $contract->organisationId())->firstOrFail();
            $membership = ContractAccess::membership($party, $contract);

            if ($membership === null) {
                $membership = ContractParty::query()->create([
                    'contract_id' => $contract->getKey(),
                    'party_id' => $party->getKey(),
                    'role' => $role,
                    'is_required_approver' => false,
                ]);
                $log->record('party.attached', $party, ['role' => $role->value]);

                return $membership;
            }

            if ($membership->role === $role) {
                return $membership;
            }

            if ($role === ContractRole::Viewer && $membership->is_required_approver) {
                throw new InvalidActionException('A required approver cannot be a viewer. Remove it as a required approver first.');
            }

            $from = $membership->role;
            $membership->update(['role' => $role]);
            $log->record('party.role_changed', $party, ['from' => $from->value, 'to' => $role->value]);

            return $membership;
        });
    }
}
