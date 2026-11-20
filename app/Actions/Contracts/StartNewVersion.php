<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Approval;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistStage;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Owner-only (D4): starts the next version of an agreed contract, whatever its status; the new version starts open
 * (D14). Copies parties, roles, required approvers, phases and active steps (each as revision 1, keeping the original
 * author). The approvals that counted on each step are carried over too, since the content is unchanged; withdrawn
 * approvals, sign-offs and comments are not. An agreed contract had every step approved, so the new version starts
 * ready for sign-off, recorded as `contract.ready` on it. Its checklists are copied with their ticks.
 */
final class StartNewVersion
{
    public function handle(Actor $actor, Contract $contract): Contract
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($actor): Contract {
            if (! $contract->isAgreed()) {
                throw new InvalidActionException('Only an agreed contract can get a new version.');
            }

            if (Contract::query()->where('previous_version_id', $contract->getKey())->exists()) {
                throw new InvalidActionException('A newer version of this contract already exists.');
            }

            $next = Contract::query()->create([
                'project_id' => $contract->project_id,
                'title' => $contract->title,
                'version' => $contract->version + 1,
                'previous_version_id' => $contract->getKey(),
                'require_human_signoff' => $contract->require_human_signoff,
            ]);

            ContractParty::query()->where('contract_id', $contract->getKey())->get()
                ->each(fn (ContractParty $membership) => ContractParty::query()->create([
                    'contract_id' => $next->id,
                    'party_id' => $membership->party_id,
                    'role' => $membership->role,
                    'is_required_approver' => $membership->is_required_approver,
                ]));

            /** @var array<string, string> $stepMap old step id => new step id */
            $stepMap = [];
            $phases = Phase::query()->where('contract_id', $contract->getKey())->orderBy('position')->get();
            foreach ($phases as $phase) {
                $copy = Phase::query()->create(['contract_id' => $next->id, 'title' => $phase->title, 'position' => $phase->position]);

                $steps = Step::query()->with('currentRevision')->where('phase_id', $phase->id)->active()->orderBy('position')->get();
                foreach ($steps->values() as $index => $step) {
                    $source = $step->currentRevision ?? throw new InvalidActionException("Step {$step->id} has no revision.");

                    $newStep = Step::query()->create([
                        'phase_id' => $copy->id,
                        'position' => $index + 1,
                        'origin_step_id' => $step->origin_step_id ?? $step->id,
                    ]);

                    $revision = StepRevision::query()->create([
                        'step_id' => $newStep->id,
                        'number' => 1,
                        'title' => $source->title,
                        'description_md' => $source->description_md,
                        'author_party_id' => $source->author_party_id,
                        'author_user_id' => $source->author_user_id,
                        'change_note' => "Carried over from version {$contract->version}, revision {$source->number}.",
                    ]);

                    $newStep->update(['current_revision_id' => $revision->id]);
                    $stepMap[$step->id] = $newStep->id;

                    // Approvals that counted on the agreed content: active, for a revision with the same content hash.
                    Approval::query()->active()
                        ->where('step_id', $step->id)
                        ->whereRelation('revision', 'content_hash', $source->content_hash)
                        ->get()
                        ->each(fn (Approval $approval) => Approval::query()->create([
                            'step_id' => $newStep->id,
                            'step_revision_id' => $revision->id,
                            'party_id' => $approval->party_id,
                            'user_id' => $approval->user_id,
                        ]));
                }
            }

            $this->copyChecklists($contract, $next, $stepMap);

            $log->record('contract.version_started', $next, ['version' => $next->version]);

            // The new version is invisible to others until this commits, so its own log needs no lock of its own.
            $report = ContractReadiness::report($next);
            if ($report->isReady()) {
                (new ActivityLog($next, $actor))->record('contract.ready', null, ['fingerprint' => $report->fingerprint]);
            }

            return $next;
        }, allowWhenLocked: true);
    }

    /**
     * The checklists carry on, ticks included; items follow their steps into the new version (an item whose step was
     * archived loses the link).
     *
     * @param  array<string, string>  $stepMap
     */
    private function copyChecklists(Contract $from, Contract $to, array $stepMap): void
    {
        $checklists = Checklist::query()->where('contract_id', $from->getKey())->with('stages.items')->orderBy('created_at')->orderBy('name')->get();

        foreach ($checklists as $checklist) {
            $copy = Checklist::query()->create([
                'contract_id' => $to->id,
                'name' => $checklist->name,
                'created_by_party_id' => $checklist->created_by_party_id,
                'created_by_user_id' => $checklist->created_by_user_id,
            ]);

            foreach ($checklist->stages as $stage) {
                $stageCopy = ChecklistStage::query()->create(['checklist_id' => $copy->id, 'title' => $stage->title, 'position' => $stage->position]);
                foreach ($stage->items as $item) {
                    ChecklistItem::query()->create([
                        ...$item->only(['position', 'title', 'notes', 'checked_at', 'checked_by_party_id', 'checked_by_user_id']),
                        'checklist_stage_id' => $stageCopy->id,
                        'step_id' => $item->step_id === null ? null : ($stepMap[$item->step_id] ?? null),
                    ]);
                }
            }
        }
    }
}
