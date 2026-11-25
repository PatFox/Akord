<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Enums\ContractStatus;
use App\Events\ContractDeleted;
use App\Exceptions\InvalidActionException;
use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Checklist;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Models\Invitation;
use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\Actor;
use App\Support\ContractAccess;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;

/**
 * Owner-only (D19): deletes a contract whose latest version is cancelled, every version of it, with everything
 * recorded on them: phases, steps, revisions, comments, approvals, sign-offs, invitations, checklists, activity and
 * the in-app notifications about it. Parties, projects and people stay. Nothing records the deletion, since the
 * contract's activity goes with it; once committed, ContractDeleted tells pages still open on a version (other than the
 * deleting tab) that it is gone.
 *
 * Runs outside ContractTransaction, which records readiness changes on the contract after the work; it follows the
 * same lock order (the given version's row first), then locks the older versions so nothing is added to them meanwhile.
 * Rows go with query deletes, children before parents, because the foreign keys restrict and StepRevision refuses
 * model deletes.
 */
final class DeleteContract
{
    public function handle(Actor $actor, Contract $contract): void
    {
        /** @var list<string> $versionIds */
        $versionIds = DB::transaction(function () use ($actor, $contract): array {
            $locked = Contract::query()->lockForUpdate()->whereKey($contract->getKey())->firstOrFail();

            ContractAccess::authorize($actor, $locked, ContractAbility::Administer);

            if (Contract::query()->where('previous_version_id', $locked->getKey())->exists()) {
                throw new InvalidActionException('A newer version of this contract exists; delete the contract from there.');
            }

            if ($locked->status !== ContractStatus::Cancelled) {
                throw new InvalidActionException('Only a cancelled contract can be deleted. Mark it cancelled first.');
            }

            // Newest first: later versions' steps point at the first version's (origin), and each version at the one before.
            $versionIds = $locked->versions()->reverse()->map(fn (Contract $version): string => $version->id)->values();
            Contract::query()->lockForUpdate()->whereKey($versionIds->slice(1)->all())->get();

            foreach ($versionIds as $versionId) {
                $this->deleteVersion($versionId);
            }

            return $versionIds->values()->all();
        }, 3);

        broadcast(new ContractDeleted($versionIds))->toOthers();
    }

    private function deleteVersion(string $contractId): void
    {
        $phases = Phase::query()->where('contract_id', $contractId)->select('id');
        $steps = Step::query()->whereIn('phase_id', $phases)->select('id');

        Checklist::query()->where('contract_id', $contractId)->delete(); // stages, items and assignees cascade
        ContractSignoff::query()->where('contract_id', $contractId)->delete();
        Invitation::query()->where('contract_id', $contractId)->delete();
        ActivityEvent::query()->where('contract_id', $contractId)->delete();
        DatabaseNotification::query()->where('data->contract_id', $contractId)->delete();

        Approval::query()->whereIn('step_id', $steps)->delete();
        // Replies first: a comment's replies point at it.
        Comment::query()->whereIn('step_id', $steps)->whereNotNull('parent_id')->delete();
        Comment::query()->whereIn('step_id', $steps)->delete();
        // A step points at its current revision, and every revision at its step.
        Step::query()->whereIn('phase_id', $phases)->update(['current_revision_id' => null]);
        StepRevision::query()->whereIn('step_id', $steps)->delete();
        Step::query()->whereIn('phase_id', $phases)->delete();
        Phase::query()->where('contract_id', $contractId)->delete();

        Contract::query()->whereKey($contractId)->delete(); // contract_party cascades
    }
}
