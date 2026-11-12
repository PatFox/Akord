<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Owner-only (D10): whether agents may sign off this contract.
 */
final class SetHumanSignOffRequired
{
    public function handle(Actor $actor, Contract $contract, bool $required): Contract
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($required): Contract {
            if ($contract->require_human_signoff !== $required) {
                $contract->update(['require_human_signoff' => $required]);
                $log->record('contract.human_signoff_changed', $contract, ['required' => $required]);
            }

            return $contract;
        });
    }
}
