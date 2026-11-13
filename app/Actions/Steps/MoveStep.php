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
use App\Support\StepOrder;
use Closure;

/**
 * Moves a step within its phase or to another phase of the same contract. Does not affect approvals or sign-offs.
 */
final class MoveStep
{
    /**
     * @param  int  $position  1-based position in the target phase's active steps
     */
    public function handle(Actor $actor, Step $step, Phase $target, int $position): Step
    {
        return $this->move($actor, $step, $target, fn (): int => $position);
    }

    /**
     * @param  int|null  $afterStepId  null: end of the phase; 0: start; otherwise directly after that step
     */
    public function handleAfter(Actor $actor, Step $step, Phase $target, ?int $afterStepId): Step
    {
        return $this->move($actor, $step, $target, fn (Phase $target, Step $step): int => StepOrder::positionAfter($target, $afterStepId, $step->id));
    }

    /**
     * @param  Closure(Phase, Step): int  $positionIn  resolved inside the contract lock
     */
    private function move(Actor $actor, Step $step, Phase $target, Closure $positionIn): Step
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($step, $target, $positionIn): Step {
            $step = $contract->findStep($step->id);
            $target = $contract->findPhase($target->id);
            $fromPhaseId = $step->phase_id;

            if ($step->isArchived()) {
                throw new InvalidActionException('Archived steps cannot be moved.');
            }

            $position = StepOrder::place($target, $step, $positionIn($target, $step));

            if ($fromPhaseId !== $target->id) {
                StepOrder::compact($fromPhaseId);
            }

            $log->record('step.moved', $step, ['from_phase_id' => $fromPhaseId, 'to_phase_id' => $target->id, 'position' => $position]);

            return $step->refresh();
        });
    }
}
