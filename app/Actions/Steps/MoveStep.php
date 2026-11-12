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
 * Moves a step to a position in a phase of the same contract. Does not affect approvals or sign-offs.
 */
final class MoveStep
{
    /**
     * @param  int  $position  1-based position in the target phase's active steps
     */
    public function handle(Actor $actor, Step $step, Phase $target, int $position): Step
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($step, $target, $position): Step {
            $step = $contract->findStep($step->id);
            $target = $contract->findPhase($target->id);
            $fromPhaseId = $step->phase_id;

            if ($step->isArchived()) {
                throw new InvalidActionException('Archived steps cannot be moved.');
            }

            $remaining = Step::query()->where('phase_id', $fromPhaseId)->active()->whereKeyNot($step->id)->orderBy('position')->pluck('id')->all();
            foreach ($remaining as $index => $id) {
                Step::query()->whereKey($id)->update(['position' => $index + 1]);
            }

            $siblings = Step::query()->where('phase_id', $target->id)->active()->whereKeyNot($step->id)->orderBy('position')->pluck('id')->all();
            $position = max(1, min($position, count($siblings) + 1));
            array_splice($siblings, $position - 1, 0, [$step->id]);

            $step->update(['phase_id' => $target->id]);
            foreach ($siblings as $index => $id) {
                Step::query()->whereKey($id)->update(['position' => $index + 1]);
            }

            $log->record('step.moved', $step, ['from_phase_id' => $fromPhaseId, 'to_phase_id' => $target->id, 'position' => $position]);

            return $step->refresh();
        });
    }
}
