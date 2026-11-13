<?php

namespace App\Support\Agents;

use App\Actions\Approvals\ApproveStep;
use App\Actions\Approvals\WithdrawApproval;
use App\Actions\Comments\AddComment;
use App\Actions\Contracts\ContractReadiness;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\WithdrawSignOff;
use App\Actions\Phases\CreatePhase;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\MoveStep;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\ActivityEvent;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Party;
use App\Models\StepRevision;
use App\Support\ActivitySequencer;
use App\Support\Actor;
use App\Support\ContractAccess;
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
     * @return list<array<string, mixed>>
     */
    public function listContracts(Actor $actor): array
    {
        $this->authorizeRead($actor);

        return array_values(Contract::query()
            ->visibleTo($this->party($actor))
            ->with('project.organisation')
            ->orderBy('id')
            ->get()
            ->map(fn (Contract $contract) => AgentPresenter::contractSummary($contract, ContractReadiness::report($contract)))
            ->all());
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(Actor $actor, int $contractId): array
    {
        $contract = $this->visibleContract($actor, $contractId);

        return AgentPresenter::contract($contract, ContractReadiness::report($contract), $this->party($actor));
    }

    public function contractMarkdown(Actor $actor, int $contractId): string
    {
        $contract = $this->visibleContract($actor, $contractId);

        return ContractMarkdown::export($contract, ContractReadiness::report($contract));
    }

    /**
     * @return array<string, mixed>
     */
    public function status(Actor $actor, int $contractId): array
    {
        $contract = $this->visibleContract($actor, $contractId);

        return AgentPresenter::status($contract, ContractReadiness::report($contract));
    }

    /**
     * @return array<string, mixed>
     */
    public function step(Actor $actor, int $contractId, int $stepId): array
    {
        $contract = $this->visibleContract($actor, $contractId);
        $step = $contract->findStep($stepId);

        return AgentPresenter::step($contract, $step, ContractReadiness::report($contract), $this->party($actor));
    }

    /**
     * @return array<string, mixed>
     */
    public function stepHistory(Actor $actor, int $contractId, int $stepId): array
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
    public function diff(Actor $actor, int $contractId, int $stepId, int $from, int $to): array
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
    public function reviseStep(Actor $actor, int $contractId, int $stepId, int $baseRevision, string $title, string $descriptionMd, ?string $changeNote): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $revision = app(ReviseStep::class)->handle($actor, $step, $baseRevision, $title, $descriptionMd, $changeNote);

        return AgentPresenter::revision($revision->load(['authorParty', 'authorUser']));
    }

    /**
     * @return array<string, mixed>
     */
    public function createStep(Actor $actor, int $contractId, int $phaseId, string $title, string $descriptionMd, ?string $changeNote, ?int $afterStepId): array
    {
        $phase = $this->visibleContract($actor, $contractId)->findPhase($phaseId);
        $step = app(CreateStep::class)->handle($actor, $phase, $title, $descriptionMd, $changeNote, $afterStepId);

        return $this->step($actor, $contractId, $step->id);
    }

    /**
     * @param  int|null  $phaseId  the target phase; null keeps the step in its phase
     * @return array<string, mixed>
     */
    public function moveStep(Actor $actor, int $contractId, int $stepId, ?int $phaseId, ?int $afterStepId): array
    {
        $contract = $this->visibleContract($actor, $contractId);
        $step = $contract->findStep($stepId);
        $target = $contract->findPhase($phaseId ?? $step->phase_id);
        $moved = app(MoveStep::class)->handleAfter($actor, $step, $target, $afterStepId);

        return ['step_id' => $moved->id, 'phase_id' => $moved->phase_id, 'position' => $moved->position];
    }

    /**
     * @return array<string, mixed>
     */
    public function archiveStep(Actor $actor, int $contractId, int $stepId): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $archived = app(ArchiveStep::class)->handle($actor, $step);

        return ['step_id' => $archived->id, 'archived' => $archived->isArchived()];
    }

    /**
     * @return array<string, mixed>
     */
    public function createPhase(Actor $actor, int $contractId, string $title): array
    {
        $phase = app(CreatePhase::class)->handle($actor, $this->visibleContract($actor, $contractId), $title);

        return ['id' => $phase->id, 'position' => $phase->position, 'content' => ['title' => $phase->title]];
    }

    /**
     * @return array<string, mixed>
     */
    public function addComment(Actor $actor, int $contractId, int $stepId, string $kind, string $bodyMd, ?int $parentId): array
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
    public function approveStep(Actor $actor, int $contractId, int $stepId, int $reviewedRevision): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);
        $approval = app(ApproveStep::class)->handle($actor, $step, $reviewedRevision);

        return ['step_id' => $step->id, 'approved_revision' => $approval->revision->number, 'party_id' => $approval->party_id];
    }

    /**
     * @return array<string, mixed>
     */
    public function withdrawApproval(Actor $actor, int $contractId, int $stepId): array
    {
        $step = $this->visibleContract($actor, $contractId)->findStep($stepId);

        return ['step_id' => $step->id, 'withdrawn' => app(WithdrawApproval::class)->handle($actor, $step)];
    }

    /**
     * @return array<string, mixed>
     */
    public function signOff(Actor $actor, int $contractId, string $fingerprint): array
    {
        $contract = $this->visibleContract($actor, $contractId);
        $signoff = app(SignOffContract::class)->handle($actor, $contract, $fingerprint);
        $contract->refresh();

        return ['contract_id' => $contract->id, 'fingerprint' => $signoff->fingerprint, 'agreed' => $contract->isAgreed()];
    }

    /**
     * @return array<string, mixed>
     */
    public function withdrawSignOff(Actor $actor, int $contractId): array
    {
        $contract = $this->visibleContract($actor, $contractId);

        return ['contract_id' => $contract->id, 'withdrawn' => app(WithdrawSignOff::class)->handle($actor, $contract)];
    }

    private function visibleContract(Actor $actor, int $contractId): Contract
    {
        $contract = Contract::query()->find($contractId)
            ?? throw (new ModelNotFoundException)->setModel(Contract::class, [$contractId]);

        ContractAccess::authorize($actor, $contract, ContractAbility::View);

        return $contract;
    }

    private function authorizeRead(Actor $actor): void
    {
        if (! $actor->tokenAllows(ContractAbility::View->tokenAbility())) {
            throw new AuthorizationException("This token lacks the 'read' ability.");
        }
    }

    private function party(Actor $actor): Party
    {
        return $actor->party ?? throw new LogicException('Agent actors always have a party.');
    }
}
