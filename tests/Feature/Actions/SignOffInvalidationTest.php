<?php

use App\Actions\Comments\AddComment;
use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Phases\CreatePhase;
use App\Actions\Phases\RenamePhase;
use App\Actions\Phases\ReorderPhases;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\MoveStep;
use App\Actions\Steps\ReorderSteps;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use Tests\Support\Scenario;

/**
 * PLAN §2 change table: alpha signs off, beta has not yet, then one change is made.
 */
beforeEach(function () {
    $this->s = Scenario::make();
    $this->phaseTwo = app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Phase two');
    $this->one = $this->s->step('One', 'First step.');
    $this->two = $this->s->step('Two', 'Second step.');
    $this->three = $this->s->step('Three', 'Third step.', $this->phaseTwo);
    $this->s->approveAll($this->one, $this->two, $this->three);

    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $this->s->readiness()->fingerprint);
});

function applyChange(string $change, object $t): void
{
    $s = $t->s;
    $owner = $s->ownerActor();

    match ($change) {
        'content edit' => app(ReviseStep::class)->handle($s->alpha, $t->one, 1, 'One', 'First step, extended.'),
        'spacing-only edit' => app(ReviseStep::class)->handle($s->alpha, $t->one, 1, ' One ', "First\n\nstep."),
        'step added' => $s->step('Four'),
        'step archived' => app(ArchiveStep::class)->handle($s->alpha, $t->two),
        'required party added' => app(SetRequiredApprover::class)->handle($owner, $s->contract, $s->party(ContractRole::Commenter)->party, true),
        'required party removed' => app(SetRequiredApprover::class)->handle($owner, $s->contract, $s->beta->party, false),
        'steps reordered' => app(ReorderSteps::class)->handle($s->alpha, $s->phase, [$t->two->id, $t->one->id]),
        'step moved to another phase' => app(MoveStep::class)->handle($s->alpha, $t->one, $t->phaseTwo, 1),
        'phase renamed' => app(RenamePhase::class)->handle($s->alpha, $s->phase, 'Renamed'),
        'phases reordered' => app(ReorderPhases::class)->handle($s->alpha, $s->contract, [$t->phaseTwo->id, $s->phase->id]),
        'comment added' => app(AddComment::class)->handle($s->beta, $t->one, CommentKind::Question, 'Why?'),
    };
}

test('changes that alter the agreed content cancel sign-offs', function (string $change) {
    applyChange($change, $this);

    expect($this->s->readiness()->signedOffPartyIds)->toBe([])
        ->and($this->s->activityCount('signoffs.invalidated'))->toBe(1);
})->with(['content edit', 'step added', 'step archived', 'required party added', 'required party removed']);

test('changes that leave the agreed content alone keep sign-offs', function (string $change) {
    applyChange($change, $this);

    expect($this->s->readiness()->signedOffPartyIds)->toBe([$this->s->alpha->party?->id])
        ->and($this->s->activityCount('signoffs.invalidated'))->toBe(0);
})->with(['spacing-only edit', 'steps reordered', 'step moved to another phase', 'phase renamed', 'phases reordered', 'comment added']);
