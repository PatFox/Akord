<?php

namespace App\Actions\Approvals;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Exceptions\StaleRevisionException;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Step;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Approves a step's current content for the actor's party. Already approved is a no-op.
 */
final class ApproveStep
{
    /**
     * @param  int  $reviewedRevisionNumber  the revision the approver read
     *
     * @throws StaleRevisionException when the step's content changed since that revision (409)
     */
    public function handle(Actor $actor, Step $step, int $reviewedRevisionNumber): Approval
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Approve, function (Contract $contract, ActivityLog $log) use ($actor, $step, $reviewedRevisionNumber): Approval {
            $step = $contract->findStep($step->id);
            $current = $step->currentRevision ?? throw new InvalidActionException('This step has no revision.');
            $party = ContractAccess::actingParty($actor);

            if ($step->isArchived()) {
                throw new InvalidActionException('Archived steps cannot be approved.');
            }

            $reviewed = $step->revisions()->where('number', $reviewedRevisionNumber)->first()
                ?? throw new InvalidActionException("This step has no revision {$reviewedRevisionNumber}.");

            if ($reviewed->content_hash !== $current->content_hash) {
                throw new StaleRevisionException("This step changed after revision {$reviewedRevisionNumber}. Review revision {$current->number} before approving.");
            }

            $existing = Approval::query()
                ->active()
                ->where('step_id', $step->id)
                ->where('party_id', $party->getKey())
                ->whereRelation('revision', 'content_hash', $current->content_hash)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $approval = Approval::query()->create([
                'step_id' => $step->id,
                'step_revision_id' => $current->id,
                'party_id' => $party->getKey(),
                'user_id' => $actor->user?->getKey(),
            ]);

            $log->record('approval.created', $approval, ['step_id' => $step->id, 'revision' => $current->number]);

            return $approval;
        });
    }
}
