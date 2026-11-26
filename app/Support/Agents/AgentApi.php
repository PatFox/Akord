<?php

namespace App\Support\Agents;

use App\Actions\Agents\CreateContractAsAgent;
use App\Actions\Approvals\ApproveStep;
use App\Actions\Approvals\WithdrawApproval;
use App\Actions\Checklists\AddChecklistItem;
use App\Actions\Checklists\CreateChecklist;
use App\Actions\Checklists\DeleteChecklistItem;
use App\Actions\Checklists\MoveChecklistItem;
use App\Actions\Checklists\SetChecklistItemChecked;
use App\Actions\Checklists\UpdateChecklistItem;
use App\Actions\Comments\AddComment;
use App\Actions\Contracts\ContractReadiness;
use App\Actions\Contracts\SetContractStatus;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\WithdrawSignOff;
use App\Actions\Phases\CreatePhase;
use App\Actions\Phases\ReorderPhases;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\MoveStep;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractAbility;
use App\Enums\ContractStatus;
use App\Exceptions\InvalidActionException;
use App\Models\ActivityEvent;
use App\Models\Checklist;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Party;
use App\Models\StepRevision;
use App\Support\ActivitySequencer;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractPlan;
use App\Support\RevisionDiff;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * Every operation agents can perform, shared by the REST API and the MCP server so
 * the two always behave the same. Writes go through the domain actions (locking,
 * lock guard, activity, live updates); reads check visibility (404) and the token's
 * 'read' ability (403).
 */
final class AgentApi
{
    /**
     * Every version the agent is attached to whose status is one of $statuses; open only when none are given (D14).
     *
     * @param  list<ContractStatus>  $statuses
     * @return list<array<string, mixed>>
     */
    public function listContracts(Actor $actor, array $statuses = []): array
    {
        $this->authorizeRead($actor);
        $statuses = $statuses === [] ? [ContractStatus::Open] : $statuses;

        return array_values(Contract::query()
            ->visibleTo($this->party($actor))
            ->whereIn('status', array_map(fn (ContractStatus $status) => $status->value, $statuses))
            ->with('project.organisation')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract) => AgentPresenter::contractSummary($contract, ContractReadiness::report($contract)))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(Actor $actor, string $contractId): array
    {
        $contract = $this->visibleContract($actor, $contractId);

        return AgentPresenter::contract($contract, ContractReadiness::report($contract), $this->party($actor));
    }

    /**
     * Starts a contract in a project the agent can see; its party becomes an editor.
     *
     * @return array<string, mixed>
     */
    public function createContract(Actor $actor, string $projectId, string $title): array
    {
        $contract = app(CreateContractAsAgent::class)->handle($actor, $projectId, $title);

        return $this->contract($actor, $contract->id);
    }

    /**
     * The contract as a Markdown plan document, the same one the contract page shows and downloads.
     */
    public function contractPlan(Actor $actor, string $contractId): string
    {
        $contract = $this->visibleContract($actor, $contractId);

        return ContractPlan::markdown($contract, ContractReadiness::report($contract));
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Actor $actor, string $contractId): array
    {
        $contract = $this->visibleContract($actor, $contractId);

        return AgentPresenter::status($contract, ContractReadiness::report($contract));
    }

    /**
     * Marks a contract open, complete or cancelled (D14); needs the 'edit' ability and an editor party.
     *
     * @return array<string, mixed>
     */
    public function setStatus(Actor $actor, string $contractId, ContractStatus $status): array
    {
        $contract = app(SetContractStatus::class)->handle($actor, $this->visibleContract($actor, $contractId), $status);

        return AgentPresenter::status($contract, ContractReadiness::report($contract));
    }

    /**
     * @return array<string, mixed>
     */
    public function step(Actor $actor, string $contractId, string $stepId): array
    {
        $contract = $this->visibleContract($actor, $contractId);
        $step = $contract->findStep($stepId);

        return AgentPresenter::step($contract, $step, ContractReadiness::report($contract), $this->party($actor));
    }

    /**
     * @return array<string, mixed>
     */
    public function stepHistory(Actor $actor, string $contractId, string $stepId): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $revisions = $step->revisions()->with(['authorParty', 'authorUser'])->get();

        return [
            'step_id' => $step->id,
            'current_revision' => $step->currentRevision?->number,
            'revisions' => $revisions->map(fn (StepRevision $r) => AgentPresenter::revision($r, $revisions->firstWhere('number', $r->number - 1)))->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function diff(Actor $actor, string $contractId, string $stepId, int $from, int $to): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $old = $step->revisions()->where('number', $from)->first() ?? throw new InvalidActionException("This step has no revision {$from}.");
        $new = $step->revisions()->where('number', $to)->first() ?? throw new InvalidActionException("This step has no revision {$to}.");

        return ['step_id' => $step->id, 'from' => $from, 'to' => $to, 'content' => ['unified_diff' => RevisionDiff::unified($old, $new)]];
    }

    /**
     * Activity on contracts the agent can see, after a cursor, in commit order.
     *
     * @return array<string, mixed>
     */
    public function activitySince(Actor $actor, int $since, int $limit = 100): array
    {
        $this->authorizeRead($actor);
        // Number any committed events whose post-commit sequencing never ran.
        ActivitySequencer::run();

        $events = ActivityEvent::query()
            ->visibleTo($this->party($actor))
            ->after($since)
            ->with(['actorUser', 'actorParty', 'subject'])
            ->limit(max(1, min($limit, 500)))
            ->get();

        return [
            'events' => $events->map(fn (ActivityEvent $e) => AgentPresenter::activity($e))->all(),
            'next_cursor' => $events->last()->seq ?? $since,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reviseStep(Actor $actor, string $contractId, string $stepId, int $baseRevision, string $title, string $descriptionMd, ?string $changeNote): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $revision = app(ReviseStep::class)->handle($actor, $step, $baseRevision, $title, $descriptionMd, $changeNote);

        return AgentPresenter::revision($revision->load(['authorParty', 'authorUser']));
    }

    /**
     * @return array<string, mixed>
     */
    public function createStep(Actor $actor, string $contractId, string $phaseId, string $title, string $descriptionMd, ?string $changeNote, ?string $afterStepId): array
    {
        $phase = $this->visibleContract($actor, $contractId)->findPhase($phaseId);
        $step = app(CreateStep::class)->handle($actor, $phase, $title, $descriptionMd, $changeNote, $afterStepId);

        return $this->step($actor, $contractId, $step->id);
    }

    /**
     * @param  string|null  $phaseId  the target phase; null keeps the step in its phase
     * @return array<string, mixed>
     */
    public function moveStep(Actor $actor, string $contractId, string $stepId, ?string $phaseId, ?string $afterStepId): array
    {
        $contract = $this->visibleContract($actor, $contractId);
        $step = $contract->findStep($stepId);
        $target = $contract->findPhase($phaseId ?? $step->phase_id);
        $moved = app(MoveStep::class)->handleAfter($actor, $step, $target, $afterStepId);

        return ['step_id' => $moved->id, 'phase_id' => $moved->phase_id, 'position' => $moved->position];
    }

    /**
     * Proposes archiving the step (D16), or archives it at once when the contract has no required approvers.
     *
     * @return array<string, mixed>
     */
    public function archiveStep(Actor $actor, string $contractId, string $stepId): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $step = app(ArchiveStep::class)->handle($actor, $step);
        $current = $step->currentRevision()->firstOrFail();

        return [
            'step_id' => $step->id,
            'archived' => $step->isArchived(),
            'archive_proposed' => ! $step->isArchived() && $current->proposes_archive,
            'revision' => $current->number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createPhase(Actor $actor, string $contractId, string $title): array
    {
        $phase = app(CreatePhase::class)->handle($actor, $this->visibleContract($actor, $contractId), $title);

        return ['id' => $phase->id, 'position' => $phase->position, 'content' => ['title' => $phase->title]];
    }

    /**
     * @param  string|null  $afterPhaseId  null: the end; "start": the start; otherwise directly after that phase
     * @return array<string, mixed>
     */
    public function movePhase(Actor $actor, string $contractId, string $phaseId, ?string $afterPhaseId): array
    {
        $phase = app(ReorderPhases::class)->handleAfter($actor, $this->visibleContract($actor, $contractId), $phaseId, $afterPhaseId);

        return ['phase_id' => $phase->id, 'position' => $phase->position];
    }

    /**
     * @return array<string, mixed>
     */
    public function addComment(Actor $actor, string $contractId, string $stepId, string $kind, string $bodyMd, ?string $parentId): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $kindEnum = CommentKind::tryFrom($kind) ?? throw new InvalidActionException('Comment kind must be question, suggestion or general.');
        $parent = $parentId === null ? null : Comment::query()->where('step_id', $step->id)->findOrFail($parentId);

        $comment = app(AddComment::class)->handle($actor, $step, $kindEnum, $bodyMd, $parent);

        return AgentPresenter::comment($comment->load(['authorParty', 'authorUser', 'revision']));
    }

    /**
     * @return array<string, mixed>
     */
    public function approveStep(Actor $actor, string $contractId, string $stepId, int $reviewedRevision): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $approval = app(ApproveStep::class)->handle($actor, $step, $reviewedRevision);

        return ['step_id' => $step->id, 'approved_revision' => $approval->revision->number, 'party_id' => $approval->party_id];
    }

    /**
     * @return array<string, mixed>
     */
    public function withdrawApproval(Actor $actor, string $contractId, string $stepId): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);

        return ['step_id' => $step->id, 'withdrawn' => app(WithdrawApproval::class)->handle($actor, $step)];
    }

    /**
     * @return array<string, mixed>
     */
    public function signOff(Actor $actor, string $contractId, string $fingerprint): array
    {
        $contract = $this->visibleContract($actor, $contractId);
        $signoff = app(SignOffContract::class)->handle($actor, $contract, $fingerprint);
        $contract->refresh();

        return ['contract_id' => $contract->id, 'fingerprint' => $signoff->fingerprint, 'agreed' => $contract->isAgreed()];
    }

    /**
     * @return array<string, mixed>
     */
    public function withdrawSignOff(Actor $actor, string $contractId): array
    {
        $contract = $this->visibleContract($actor, $contractId);

        return ['contract_id' => $contract->id, 'withdrawn' => app(WithdrawSignOff::class)->handle($actor, $contract)];
    }

    /**
     * Every checklist of the contract version, oldest first (then by name: timestamps are to the second).
     *
     * @return array{checklists: list<array<string, mixed>>}
     */
    public function checklists(Actor $actor, string $contractId): array
    {
        $checklists = Checklist::query()->where('contract_id', $this->visibleContract($actor, $contractId)->id)->orderBy('created_at')->orderBy('name')->get();

        return ['checklists' => array_values($checklists->map(fn (Checklist $checklist) => AgentPresenter::checklist($checklist))->all())];
    }

    /**
     * @param  list<array{title: string, items: list<array{title: string, notes?: string|null, step_id?: string|null}>}>|null  $stages
     * @return array{checklist: array<string, mixed>}
     */
    public function createChecklist(Actor $actor, string $contractId, string $name, ?array $stages, bool $replace): array
    {
        $checklist = app(CreateChecklist::class)->handle($actor, $this->visibleContract($actor, $contractId), $name, $stages, $replace);

        return ['checklist' => AgentPresenter::checklist($checklist)];
    }

    /**
     * @return array<string, mixed>
     */
    public function setChecklistItemChecked(Actor $actor, string $contractId, string $itemId, bool $checked): array
    {
        $item = app(SetChecklistItemChecked::class)->handle($actor, $this->visibleContract($actor, $contractId), $itemId, $checked);

        return AgentPresenter::checklistItem($item->load(['checkedByParty', 'checkedByUser']));
    }

    /**
     * @return array<string, mixed>
     */
    public function addChecklistItem(Actor $actor, string $contractId, string $stageId, string $title, ?string $notes, ?string $stepId): array
    {
        $item = app(AddChecklistItem::class)->handle($actor, $this->visibleContract($actor, $contractId), $stageId, $title, $notes, $stepId);

        return AgentPresenter::checklistItem($item);
    }

    /**
     * @param  array{title?: string, notes?: string|null, step_id?: string|null}  $changes  only the keys given change
     * @return array<string, mixed>
     */
    public function updateChecklistItem(Actor $actor, string $contractId, string $itemId, array $changes): array
    {
        $item = app(UpdateChecklistItem::class)->handle($actor, $this->visibleContract($actor, $contractId), $itemId, $changes);

        return AgentPresenter::checklistItem($item->load(['checkedByParty', 'checkedByUser']));
    }

    /**
     * @return array{item_id: string, deleted: true}
     */
    public function deleteChecklistItem(Actor $actor, string $contractId, string $itemId): array
    {
        app(DeleteChecklistItem::class)->handle($actor, $this->visibleContract($actor, $contractId), $itemId);

        return ['item_id' => $itemId, 'deleted' => true];
    }

    /**
     * @return array<string, mixed>
     */
    public function moveChecklistItem(Actor $actor, string $contractId, string $itemId, ?string $stageId, ?string $afterItemId): array
    {
        $item = app(MoveChecklistItem::class)->handle($actor, $this->visibleContract($actor, $contractId), $itemId, $stageId, $afterItemId);

        return AgentPresenter::checklistItem($item->load(['checkedByParty', 'checkedByUser']));
    }

    private function visibleContract(Actor $actor, string $contractId): Contract
    {
        $contract = Contract::query()->find($contractId)
            ?? throw (new ModelNotFoundException)->setModel(Contract::class, [$contractId]);

        ContractAccess::authorize($actor, $contract, ContractAbility::View);

        return $contract;
    }

    private function authorizeRead(Actor $actor): void
    {
        if (! $actor->tokenAllows(ContractAbility::View->tokenAbility())) {
            throw new AuthorizationException("This agent party lacks the 'read' ability.");
        }
    }

    private function party(Actor $actor): Party
    {
        return $actor->party ?? throw new LogicException('Agent actors always have a party.');
    }
}
