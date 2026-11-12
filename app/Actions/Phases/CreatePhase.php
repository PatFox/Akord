<?php

namespace App\Actions\Phases;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Models\Phase;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Adds a phase at the end of the contract.
 */
final class CreatePhase
{
    public function handle(Actor $actor, Contract $contract, string $title): Phase
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($title): Phase {
            $phase = Phase::query()->create([
                'contract_id' => $contract->getKey(),
                'title' => $title,
                'position' => (int) Phase::query()->where('contract_id', $contract->getKey())->max('position') + 1,
            ]);

            $log->record('phase.created', $phase, ['title' => $title]);

            return $phase;
        });
    }
}
