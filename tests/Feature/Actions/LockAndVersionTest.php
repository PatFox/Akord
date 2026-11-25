<?php

use App\Actions\Approvals\ApproveStep;
use App\Actions\Approvals\WithdrawApproval;
use App\Actions\Checklists\CreateChecklist;
use App\Actions\Checklists\SetChecklistAssignee;
use App\Actions\Comments\AddComment;
use App\Actions\Comments\ResolveComment;
use App\Actions\Contracts\AttachParty;
use App\Actions\Contracts\ContractReadiness;
use App\Actions\Contracts\DeleteContract;
use App\Actions\Contracts\InviteToContract;
use App\Actions\Contracts\SetContractStatus;
use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\StartNewVersion;
use App\Actions\Contracts\WithdrawSignOff;
use App\Actions\Phases\CreatePhase;
use App\Actions\Phases\RenamePhase;
use App\Actions\Phases\ReorderPhases;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\MoveStep;
use App\Actions\Steps\ReorderSteps;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Enums\ContractState;
use App\Enums\ContractStatus;
use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Models\Invitation;
use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\Actor;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->one = $this->s->step('One', 'First step.');
    $this->two = $this->s->step('Two', 'Second step.');
    $this->archived = $this->s->step('Dropped', 'Archived before agreement.');
    $this->s->archive($this->archived);
    $this->comment = app(AddComment::class)->handle($this->s->beta, $this->one, CommentKind::Question, 'Scope?');
    $this->spare = $this->s->party(null);
    $this->s->approveAll($this->one, $this->two);

    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);
});

function attemptWrite(string $write, object $t): int
{
    $s = $t->s;

    return status(fn () => match ($write) {
        'create phase' => app(CreatePhase::class)->handle($s->alpha, $s->contract, 'New'),
        'rename phase' => app(RenamePhase::class)->handle($s->alpha, $s->phase, 'New'),
        'reorder phases' => app(ReorderPhases::class)->handle($s->alpha, $s->contract, [$s->phase->id]),
        'create step' => app(CreateStep::class)->handle($s->alpha, $s->phase, 'New', 'New'),
        'revise step' => app(ReviseStep::class)->handle($s->alpha, $t->one, 1, 'New', 'New'),
        'reorder steps' => app(ReorderSteps::class)->handle($s->alpha, $s->phase, [$t->two->id, $t->one->id]),
        'move step' => app(MoveStep::class)->handle($s->alpha, $t->one, $s->phase, 2),
        'archive step' => app(ArchiveStep::class)->handle($s->alpha, $t->one),
        'add comment' => app(AddComment::class)->handle($s->beta, $t->one, CommentKind::General, 'Late'),
        'resolve comment' => app(ResolveComment::class)->handle($s->beta, $t->comment),
        'approve step' => app(ApproveStep::class)->handle($s->beta, $t->one, 1),
        'withdraw approval' => app(WithdrawApproval::class)->handle($s->beta, $t->one),
        'attach party' => app(AttachParty::class)->handle($s->ownerActor(), $s->contract, $t->spare->party, ContractRole::Viewer),
        'set required approver' => app(SetRequiredApprover::class)->handle($s->ownerActor(), $s->contract, $s->beta->party, false),
        'sign off' => app(SignOffContract::class)->handle($s->alpha, $s->contract, $s->readiness()->fingerprint),
        'withdraw sign-off' => app(WithdrawSignOff::class)->handle($s->alpha, $s->contract),
    });
}

test('every write to an agreed contract is locked', function (string $write) {
    expect(attemptWrite($write, $this))->toBe(423);
})->with([
    'create phase', 'rename phase', 'reorder phases', 'create step', 'revise step', 'reorder steps',
    'move step', 'archive step', 'add comment', 'resolve comment', 'approve step', 'withdraw approval',
    'attach party', 'set required approver', 'sign off', 'withdraw sign-off',
]);

test('a new version carries over the same content, parties and approvals, and starts ready for sign-off without sign-offs', function () {
    // A withdrawn approval on the agreed version must not be carried over.
    Approval::query()->create(['step_id' => $this->one->id, 'step_revision_id' => $this->one->current_revision_id, 'party_id' => $this->spare->party?->id, 'withdrawn_at' => now()]);

    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    $steps = Step::query()->with('currentRevision')->whereRelation('phase', 'contract_id', $next->id)->orderBy('position')->get();
    $report = ContractReadiness::report($next);
    $required = collect([$this->s->alpha->party?->id, $this->s->beta->party?->id])->sort()->values()->all();

    expect($next->version)->toBe(2)
        ->and($next->previous_version_id)->toBe($this->s->contract->id)
        ->and($report->state)->toBe(ContractState::ReadyForSignoff)
        ->and($report->outstanding)->toBe([])
        ->and($steps->map(fn (Step $step) => [$step->currentRevision?->title, $step->currentRevision?->number, $step->origin_step_id])->all())
        ->toBe([['One', 1, $this->one->id], ['Two', 1, $this->two->id]])
        ->and($steps->map(fn (Step $step) => Approval::query()->active()->where('step_id', $step->id)->where('step_revision_id', $step->current_revision_id)->pluck('party_id')->sort()->values()->all())->all())
        ->toBe([$required, $required])
        ->and(Approval::query()->whereIn('step_id', $steps->pluck('id'))->count())->toBe(4)
        ->and(Comment::query()->whereIn('step_id', $steps->pluck('id'))->count())->toBe(0)
        ->and(ContractSignoff::query()->where('contract_id', $next->id)->count())->toBe(0)
        ->and($next->requiredApprovers()->pluck('parties.id')->sort()->values()->all())->toBe($required)
        ->and(ActivityEvent::query()->where('contract_id', $next->id)->pluck('type')->all())->toBe(['contract.ready']);
});

test('a version can be continued once, only when agreed, and only by an owner', function () {
    expect(status(fn () => app(StartNewVersion::class)->handle($this->s->alpha, $this->s->contract)))->toBe(403);

    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    expect(status(fn () => app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract)))->toBe(422)
        ->and(status(fn () => app(StartNewVersion::class)->handle($this->s->ownerActor(), $next)))->toBe(422);
});

test('every write to a closed version is locked until it is reopened; invitations still work', function (string $write) {
    // Fixture: the same contract before agreement, then cancelled.
    $this->s->contract->forceFill(['agreed_at' => null, 'agreed_fingerprint' => null])->save();
    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Cancelled);
    Mail::fake();

    expect(attemptWrite($write, $this))->toBe(423)
        ->and(status(fn () => app(InviteToContract::class)->handle($this->s->ownerActor(), $this->s->contract, $this->s->beta->party, 'late@example.com')))->toBe(200);
})->with([
    'create phase', 'rename phase', 'reorder phases', 'create step', 'revise step', 'reorder steps',
    'move step', 'archive step', 'add comment', 'resolve comment', 'approve step', 'withdraw approval',
    'attach party', 'set required approver', 'sign off', 'withdraw sign-off',
]);

test('closing and reopening keeps approvals and sign-offs and records only the status changes', function () {
    $s = Scenario::make();
    $s->approveAll($s->step());
    $fingerprint = $s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($s->alpha, $s->contract, $fingerprint);
    $events = $s->contract->activityEvents()->count();

    app(SetContractStatus::class)->handle($s->alpha, $s->contract, ContractStatus::Complete);
    expect(status(fn () => app(SignOffContract::class)->handle($s->beta, $s->contract, $fingerprint)))->toBe(423);

    app(SetContractStatus::class)->handle($s->alpha, $s->contract, ContractStatus::Open);

    // toEqual: MySQL's JSON column stores payload keys in its own order.
    expect($s->contract->activityEvents()->orderBy('position')->skip($events)->take(10)->get()->map(fn (ActivityEvent $e) => [$e->type, $e->payload])->all())
        ->toEqual([['contract.status_changed', ['from' => 'open', 'to' => 'complete']], ['contract.status_changed', ['from' => 'complete', 'to' => 'open']]])
        ->and($s->readiness()->signedOffPartyIds)->toBe([$s->alpha->party?->id]);

    app(SignOffContract::class)->handle($s->beta, $s->contract, $fingerprint);
    expect($s->contract->fresh()?->isAgreed())->toBeTrue();
});

test('the status changes with the edit ability, on agreed versions too, only on the latest version, and only once', function () {
    $readOnlyAgent = $this->s->party(ContractRole::Editor, agent: true, abilities: ['read']);

    expect(status(fn () => app(SetContractStatus::class)->handle($this->s->beta, $this->s->contract, ContractStatus::Complete)))->toBe(403)
        ->and(status(fn () => app(SetContractStatus::class)->handle($readOnlyAgent, $this->s->contract, ContractStatus::Complete)))->toBe(403);

    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Complete);
    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Complete);
    expect($this->s->activityCount('contract.status_changed'))->toBe(1);

    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    expect($next->fresh()?->status)->toBe(ContractStatus::Open)
        ->and($this->s->contract->fresh()?->status)->toBe(ContractStatus::Complete)
        ->and(status(fn () => app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Open)))->toBe(422);
});

test('an owner can change a role on an agreed version without touching its agreement, but not while it is closed', function () {
    $fingerprint = $this->s->contract->fresh()?->agreed_fingerprint;
    $changeRole = fn (Actor $party, ContractRole $role) => status(fn () => app(AttachParty::class)->handle($this->s->ownerActor(), $this->s->contract, $party->party, $role));

    expect(status(fn () => app(SetContractStatus::class)->handle($this->s->beta, $this->s->contract, ContractStatus::Complete)))->toBe(403)
        ->and($changeRole($this->s->beta, ContractRole::Editor))->toBe(200)
        ->and($changeRole($this->s->beta, ContractRole::Viewer))->toBe(422)
        ->and($this->s->activityCount('party.role_changed'))->toBe(1)
        ->and($this->s->contract->fresh()?->isAgreed())->toBeTrue()
        ->and($this->s->readiness()->fingerprint)->toBe($fingerprint)
        ->and($this->s->readiness()->signedOffPartyIds)->toHaveCount(2);

    // The new role takes effect: beta may now change the status, which closes the version to role changes.
    app(SetContractStatus::class)->handle($this->s->beta, $this->s->contract, ContractStatus::Complete);
    expect($changeRole($this->s->alpha, ContractRole::Commenter))->toBe(423);

    app(SetContractStatus::class)->handle($this->s->beta, $this->s->contract, ContractStatus::Open);
    expect($changeRole($this->s->alpha, ContractRole::Commenter))->toBe(200);
});

test('an owner deletes a cancelled contract with every version and everything recorded on them; other contracts and the parties stay', function () {
    Mail::fake();
    $elsewhere = Scenario::make();
    $elsewhereStep = $elsewhere->step();
    app(AddComment::class)->handle($elsewhere->beta, $elsewhereStep, CommentKind::Question, 'Kept?');

    // Version 2, open, with a comment thread, a checklist with an assignee, an invitation and in-app notifications.
    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);
    $step = Step::query()->whereRelation('phase', 'contract_id', $next->id)->orderBy('position')->firstOrFail();
    $question = app(AddComment::class)->handle($this->s->beta, $step, CommentKind::Question, 'Why?');
    app(AddComment::class)->handle($this->s->alpha, $step, CommentKind::General, 'Because.', $question);
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $next, 'QA');
    app(SetChecklistAssignee::class)->handle($this->s->ownerActor(), $next, $checklist->id, $this->s->beta->party, true);
    app(InviteToContract::class)->handle($this->s->ownerActor(), $next, $this->s->beta->party, 'late@example.com');
    app(SetContractStatus::class)->handle($this->s->alpha, $next, ContractStatus::Cancelled);

    $contractIds = [$this->s->contract->id, $next->id];
    $stepIds = Step::query()->whereIn('phase_id', Phase::query()->whereIn('contract_id', $contractIds)->select('id'))->pluck('id')->all();
    $notifications = fn () => DatabaseNotification::query()->whereIn('data->contract_id', $contractIds)->count();
    expect($notifications())->toBeGreaterThan(0)
        ->and(DB::table('checklist_party')->count())->toBe(1);

    app(DeleteContract::class)->handle($this->s->ownerActor(), $next);

    expect(Contract::query()->whereKey($contractIds)->count())->toBe(0)
        ->and(DB::table('contract_party')->whereIn('contract_id', $contractIds)->count())->toBe(0)
        ->and(Phase::query()->whereIn('contract_id', $contractIds)->count())->toBe(0)
        ->and(Step::query()->whereKey($stepIds)->count())->toBe(0)
        ->and(StepRevision::query()->whereIn('step_id', $stepIds)->count())->toBe(0)
        ->and(Comment::query()->whereIn('step_id', $stepIds)->count())->toBe(0)
        ->and(Approval::query()->whereIn('step_id', $stepIds)->count())->toBe(0)
        ->and(ContractSignoff::query()->whereIn('contract_id', $contractIds)->count())->toBe(0)
        ->and(Invitation::query()->whereIn('contract_id', $contractIds)->count())->toBe(0)
        ->and(ActivityEvent::query()->whereIn('contract_id', $contractIds)->count())->toBe(0)
        ->and(DB::table('checklists')->count())->toBe(0)
        ->and(DB::table('checklist_items')->count())->toBe(0)
        ->and(DB::table('checklist_party')->count())->toBe(0)
        ->and($notifications())->toBe(0)
        ->and($this->s->alpha->party?->fresh())->not->toBeNull()
        ->and($this->s->contract->project->fresh())->not->toBeNull()
        ->and($elsewhere->contract->fresh())->not->toBeNull()
        ->and(Comment::query()->where('step_id', $elsewhereStep->id)->count())->toBe(1)
        ->and(StepRevision::query()->where('step_id', $elsewhereStep->id)->count())->toBe(1);
});

test('only an owner deletes a contract, from its latest version, once that is cancelled', function () {
    $delete = fn (Actor $actor, Contract $contract) => status(fn () => app(DeleteContract::class)->handle($actor, $contract));
    $agent = $this->s->party(ContractRole::Editor, agent: true);

    // A cancelled version that was continued anyway: the contract is its newest version, which is open.
    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Cancelled);
    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    expect($delete($this->s->ownerActor(), $this->s->contract))->toBe(422)
        ->and($delete($this->s->ownerActor(), $next))->toBe(422);

    app(SetContractStatus::class)->handle($this->s->alpha, $next, ContractStatus::Complete);
    expect($delete($this->s->ownerActor(), $next))->toBe(422);

    app(SetContractStatus::class)->handle($this->s->alpha, $next, ContractStatus::Cancelled);
    expect($delete($this->s->alpha, $next))->toBe(403)
        ->and($delete($agent, $next))->toBe(403)
        ->and($delete($this->s->ownerActor(), $next))->toBe(200)
        ->and(Contract::query()->whereKey([$this->s->contract->id, $next->id])->count())->toBe(0);
});
