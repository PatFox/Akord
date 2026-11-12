<?php

namespace App\Actions\Steps;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Adds a step, with revision 1, at the end of a phase. Sends the contract back to draft.
 */
final class CreateStep
{
    public function handle(Actor $actor, Phase $phase, string $title, string $descriptionMd, ?string $changeNote = null): Step
    {
        return ContractTransaction::run($phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($actor, $phase, $title, $descriptionMd, $changeNote): Step {
            $phase = $contract->findPhase($phase->id);

            $step = Step::query()->create([
                'phase_id' => $phase->id,
                'position' => (int) Step::query()->where('phase_id', $phase->id)->max('position') + 1,
            ]);

            $revision = StepRevision::query()->create([
                'step_id' => $step->id,
                'number' => 1,
                'title' => $title,
                'description_md' => $descriptionMd,
                'author_party_id' => ContractAccess::actingParty($actor)->getKey(),
                'author_user_id' => $actor->user?->getKey(),
                'change_note' => $changeNote,
            ]);

            $step->update(['current_revision_id' => $revision->id]);
            $log->record('step.created', $step, ['phase_id' => $phase->id, 'revision' => 1]);

            return $step;
        });
    }
}
