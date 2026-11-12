<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Owner-only (D4): starts the next version of an agreed contract. Copies parties, roles, required approvers, phases and active steps (each as revision 1, keeping the original author); no approvals, sign-offs or comments. The new version starts in draft.
 */
final class StartNewVersion
{
    public function handle(Actor $actor, Contract $contract): Contract
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log): Contract {
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
                }
            }

            $log->record('contract.version_started', $next, ['version' => $next->version]);

            return $next;
        }, allowWhenAgreed: true);
    }
}
