<?php

use App\Actions\Approvals\ApproveStep;
use App\Actions\Approvals\WithdrawApproval;
use App\Actions\Phases\CreatePhase;
use App\Actions\Phases\RenamePhase;
use App\Actions\Steps\MoveStep;
use App\Actions\Steps\ReorderSteps;
use App\Actions\Steps\ReviseStep;
use App\Models\Approval;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step('Build the API', "Endpoints for\ncontracts.");
    $this->s->approve($this->s->alpha, $this->step);
});

function alphaApprovalCounts(Scenario $s, int $stepId): bool
{
    return ! collect($s->readiness()->outstanding)
        ->contains(fn (array $o) => $o['step_id'] === $stepId && $o['party_id'] === $s->alpha->party?->id);
}

test('a content edit cancels the approval but keeps it in history', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Endpoints for contracts and steps.');

    expect(alphaApprovalCounts($this->s, $this->step->id))->toBeFalse()
        ->and(Approval::query()->where('step_id', $this->step->id)->count())->toBe(1)
        ->and(Approval::query()->where('step_id', $this->step->id)->first()?->withdrawn_at)->toBeNull();
});

test('a spacing-only edit records a new revision and keeps the approval', function () {
    $revision = app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, ' Build  the API', "Endpoints for\r\n\r\ncontracts. ");

    expect($revision->number)->toBe(2)
        ->and($this->step->fresh()?->current_revision_id)->toBe($revision->id)
        ->and(alphaApprovalCounts($this->s, $this->step->id))->toBeTrue();
});

test('reordering, moving the step, and renaming its phase keep the approval', function () {
    $other = $this->s->step('Second step');
    $phaseTwo = app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Phase two');

    app(ReorderSteps::class)->handle($this->s->alpha, $this->s->phase, [$other->id, $this->step->id]);
    app(MoveStep::class)->handle($this->s->alpha, $this->step, $phaseTwo, 1);
    app(RenamePhase::class)->handle($this->s->alpha, $phaseTwo, 'Renamed');

    expect(alphaApprovalCounts($this->s, $this->step->id))->toBeTrue()
        ->and($this->step->fresh()?->phase_id)->toBe($phaseTwo->id);
});

test('approving content that changed since the reviewed revision is a conflict', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', 'Different scope.');

    expect(status(fn () => app(ApproveStep::class)->handle($this->s->beta, $this->step, 1)))->toBe(409)
        ->and(status(fn () => app(ApproveStep::class)->handle($this->s->beta, $this->step, 2)))->toBe(200);
});

test('approving a revision that differs from the current one only in spacing succeeds', function () {
    app(ReviseStep::class)->handle($this->s->alpha, $this->step, 1, 'Build the API', "Endpoints  for\ncontracts.");

    expect(status(fn () => app(ApproveStep::class)->handle($this->s->beta, $this->step, 1)))->toBe(200);
});

test('approving again while an approval counts is a no-op, and withdrawing stops it counting', function () {
    $this->s->approve($this->s->alpha, $this->step);
    expect(Approval::query()->where('step_id', $this->step->id)->count())->toBe(1);

    expect(app(WithdrawApproval::class)->handle($this->s->alpha, $this->step))->toBe(1)
        ->and(alphaApprovalCounts($this->s, $this->step->id))->toBeFalse();
});
