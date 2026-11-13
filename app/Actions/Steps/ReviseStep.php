<?php

namespace App\Actions\Steps;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Exceptions\StaleRevisionException;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Records a new revision of a step's title and description. A content change cancels its approvals and the contract's sign-offs; a spacing-only change does not (D11). Identical text is a no-op.
 */
final class ReviseStep
{
    /**
     * @param  int  $baseRevisionNumber  the revision the editor started from
     *
     * @throws StaleRevisionException when another edit landed first (409)
     */
    public function handle(Actor $actor, Step $step, int $baseRevisionNumber, string $title, string $descriptionMd, ?string $changeNote = null): StepRevision
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($actor, $step, $baseRevisionNumber, $title, $descriptionMd, $changeNote): StepRevision {
            $step = $contract->findStep($step->id);
            $current = $step->currentRevision ?? throw new InvalidActionException('This step has no revision.');

            if ($step->isArchived()) {
                throw new InvalidActionException('Archived steps cannot be edited.');
            }

            if ($current->number !== $baseRevisionNumber) {
                throw new StaleRevisionException("This step is now at revision {$current->number}; you edited revision {$baseRevisionNumber}. Review the latest revision and try again.");
            }

            if ($current->title === $title && $current->description_md === $descriptionMd) {
                return $current;
            }

            $revision = StepRevision::query()->create([
                'step_id' => $step->id,
                'number' => $current->number + 1,
                'title' => $title,
                'description_md' => $descriptionMd,
                'author_party_id' => ContractAccess::actingParty($actor)->getKey(),
                'author_user_id' => $actor->user?->getKey(),
                'change_note' => $changeNote,
            ]);

            $step->update(['current_revision_id' => $revision->id]);

            $contentChanged = $revision->content_hash !== $current->content_hash;

            $log->record('step.revised', $step, [
                'revision' => $revision->number,
                'previous_revision' => $current->number,
                'content_changed' => $contentChanged,
                // Parties whose approval counted until this edit (notifications tell them).
                'cancelled_party_ids' => $contentChanged
                    ? Approval::query()->active()
                        ->where('step_id', $step->id)
                        ->whereRelation('revision', 'content_hash', $current->content_hash)
                        ->orderBy('party_id')
                        ->pluck('party_id')
                        ->map(fn (mixed $id): int => (int) $id)
                        ->unique()->values()->all()
                    : [],
            ]);

            return $revision;
        });
    }
}
