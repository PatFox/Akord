<?php

namespace App\Actions\Steps;

use App\Actions\Contracts\ContractReadiness;
use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Archives a step (steps are never deleted) once every required party agrees (D16). With required approvers this
 * saves a revision proposing the archive, with the same text: the step stays in the contract and its approvals stop
 * counting until every required party approves that revision, and settle() then archives it. Any later revision of
 * the step (ReviseStep) withdraws the proposal. Without required approvers the step is archived at once. Archived
 * steps and pending proposals are no-ops.
 */
final class ArchiveStep
{
    public function handle(Actor $actor, Step $step): Step
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($actor, $step): Step {
            $step = $contract->findStep($step->id);
            $current = $step->currentRevision ?? throw new InvalidActionException('This step has no revision.');

            if ($step->isArchived() || $current->proposes_archive) {
                return $step;
            }

            if (! $contract->requiredApprovers()->exists()) {
                $step->update(['archived_at' => now()]);
                $log->record('step.archived', $step);

                return $step;
            }

            $proposal = StepRevision::query()->create([
                'step_id' => $step->id,
                'number' => $current->number + 1,
                'title' => $current->title,
                'description_md' => $current->description_md,
                'proposes_archive' => true,
                'author_party_id' => ContractAccess::actingParty($actor)->getKey(),
                'author_user_id' => $actor->user?->getKey(),
            ]);

            $step->update(['current_revision_id' => $proposal->id]);

            $log->record('step.archive_proposed', $step, [
                'revision' => $proposal->number,
                'previous_revision' => $current->number,
                // Parties whose approval of the text counted until now (notifications tell them).
                'cancelled_party_ids' => Approval::partyIdsApproving($current),
            ]);

            return $step->refresh();
        });
    }

    /**
     * Archives the contract's steps whose archive proposal every required party approved, or that no required party
     * is left to approve. ContractTransaction runs this after every change to an open contract, so it follows the
     * last approval, a required party's removal, or a reopening.
     */
    public static function settle(Contract $contract, ActivityLog $log): void
    {
        $proposals = Step::query()
            ->active()
            ->whereRelation('phase', 'contract_id', $contract->getKey())
            ->whereRelation('currentRevision', 'proposes_archive', true)
            ->with('currentRevision')
            ->get();

        if ($proposals->isEmpty()) {
            return;
        }

        /** @var list<string> $required */
        $required = $contract->requiredApprovers()->pluck('parties.id')->all();
        /** @var list<string> $stepIds */
        $stepIds = $proposals->modelKeys();
        $approved = ContractReadiness::countingApprovals($stepIds, $required);

        foreach ($proposals as $step) {
            foreach ($required as $partyId) {
                if (! isset($approved[$step->id.':'.$partyId])) {
                    continue 2;
                }
            }

            $step->update(['archived_at' => now()]);
            $log->record('step.archived', $step, ['approved_revision' => $step->currentRevision?->number]);
        }
    }
}
