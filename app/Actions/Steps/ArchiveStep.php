<?php

namespace App\Actions\Steps;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Models\Step;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Archives a step (steps are never deleted). Cancels the contract's sign-offs.
 */
final class ArchiveStep
{
    public function handle(Actor $actor, Step $step): Step
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($step): Step {
            $step = $contract->findStep($step->id);

            if (! $step->isArchived()) {
                $step->update(['archived_at' => now()]);
                $log->record('step.archived', $step);
            }

            return $step;
        });
    }
}
