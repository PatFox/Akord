<?php

namespace App\Actions\Phases;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\Phase;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Reorders a contract's phases. Does not affect approvals or sign-offs.
 */
final class ReorderPhases
{
    /**
     * @param  list<int>  $phaseIds  every phase of the contract, in the new order
     */
    public function handle(Actor $actor, Contract $contract, array $phaseIds): void
    {
        ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($phaseIds): void {
            $existing = Phase::query()->where('contract_id', $contract->getKey())->pluck('id')->map(intval(...))->sort()->values()->all();
            $requested = collect($phaseIds)->map(intval(...))->sort()->values()->all();

            if ($existing !== $requested) {
                throw new InvalidActionException('The new order must list every phase of the contract exactly once.');
            }

            foreach ($phaseIds as $index => $phaseId) {
                Phase::query()->whereKey($phaseId)->update(['position' => $index + 1]);
            }

            $log->record('phases.reordered', null, ['phase_ids' => $phaseIds]);
        });
    }
}
