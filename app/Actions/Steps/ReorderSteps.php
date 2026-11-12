<?php

namespace App\Actions\Steps;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\Phase;
use App\Models\Step;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Reorders a phase's active steps. Does not affect approvals or sign-offs.
 */
final class ReorderSteps
{
    /**
     * @param  list<int>  $stepIds  every active step of the phase, in the new order
     */
    public function handle(Actor $actor, Phase $phase, array $stepIds): void
    {
        ContractTransaction::run($phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($phase, $stepIds): void {
            $phase = $contract->findPhase($phase->id);

            $existing = Step::query()->where('phase_id', $phase->id)->active()->pluck('id')->map(intval(...))->sort()->values()->all();
            $requested = collect($stepIds)->map(intval(...))->sort()->values()->all();

            if ($existing !== $requested) {
                throw new InvalidActionException('The new order must list every active step of the phase exactly once.');
            }

            foreach ($stepIds as $index => $stepId) {
                Step::query()->whereKey($stepId)->update(['position' => $index + 1]);
            }

            $log->record('steps.reordered', $phase, ['step_ids' => $stepIds]);
        });
    }
}
