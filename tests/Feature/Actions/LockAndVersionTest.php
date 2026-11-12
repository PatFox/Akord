<?php

use App\Actions\Approvals\ApproveStep;
use App\Actions\Approvals\WithdrawApproval;
use App\Actions\Comments\AddComment;
use App\Actions\Comments\ResolveComment;
use App\Actions\Contracts\AttachParty;
use App\Actions\Contracts\ContractReadiness;
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
use App\Models\Approval;
use App\Models\Comment;
use App\Models\ContractSignoff;
use App\Models\Step;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->one = $this->s->step('One', 'First step.');
    $this->two = $this->s->step('Two', 'Second step.');
    $this->archived = $this->s->step('Dropped', 'Archived before agreement.');
    app(ArchiveStep::class)->handle($this->s->alpha, $this->archived);
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

test('a new version starts in draft with the same content and parties, and no approvals or sign-offs', function () {
    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    $steps = Step::query()->with('currentRevision')->whereRelation('phase', 'contract_id', $next->id)->orderBy('position')->get();

    expect($next->version)->toBe(2)
        ->and($next->previous_version_id)->toBe($this->s->contract->id)
        ->and(ContractReadiness::report($next)->state)->toBe(ContractState::Draft)
        ->and($steps->map(fn (Step $step) => [$step->currentRevision?->title, $step->currentRevision?->number, $step->origin_step_id])->all())
        ->toBe([['One', 1, $this->one->id], ['Two', 1, $this->two->id]])
        ->and(Approval::query()->whereIn('step_id', $steps->pluck('id'))->count())->toBe(0)
        ->and(Comment::query()->whereIn('step_id', $steps->pluck('id'))->count())->toBe(0)
        ->and(ContractSignoff::query()->where('contract_id', $next->id)->count())->toBe(0)
        ->and($next->requiredApprovers()->pluck('parties.id')->sort()->values()->all())
        ->toBe(collect([$this->s->alpha->party?->id, $this->s->beta->party?->id])->sort()->values()->all());
});

test('a version can be continued once, only when agreed, and only by an owner', function () {
    expect(status(fn () => app(StartNewVersion::class)->handle($this->s->alpha, $this->s->contract)))->toBe(403);

    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    expect(status(fn () => app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract)))->toBe(422)
        ->and(status(fn () => app(StartNewVersion::class)->handle($this->s->ownerActor(), $next)))->toBe(422);
});
