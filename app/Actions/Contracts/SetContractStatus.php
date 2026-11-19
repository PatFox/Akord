<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Enums\ContractStatus;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * D14: marks the latest version open, complete or cancelled. Needs the edit ability, and works on locked
 * versions, since reopening is how a closed one is unlocked. Status is not part of the fingerprint, so
 * approvals and sign-offs survive closing and reopening.
 */
final class SetContractStatus
{
    public function handle(Actor $actor, Contract $contract, ContractStatus $status): Contract
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($status): Contract {
            if (Contract::query()->where('previous_version_id', $contract->getKey())->exists()) {
                throw new InvalidActionException('A newer version of this contract exists; change the status there.');
            }

            $from = $contract->status;
            if ($from !== $status) {
                $contract->update(['status' => $status]);
                $log->record('contract.status_changed', $contract, ['from' => $from->value, 'to' => $status->value]);
            }

            return $contract;
        }, allowWhenLocked: true);
    }
}
