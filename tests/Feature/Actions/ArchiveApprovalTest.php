<?php

use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\ReviseStep;
use App\Enums\ContractRole;
use App\Models\StepRevision;
use App\Support\ContractPlan;
use Tests\Support\Scenario;

/**
 * D16: archiving a step needs every required party's approval of a revision proposing it.
 */
beforeEach(function () {
    $this->s = Scenario::make();
    $this->keep = $this->s->step('Keep', 'Stays.');
    $this->drop = $this->s->step('Drop', 'Not needed.');
    $this->s->approveAll($this->keep, $this->drop);
});

test('a proposal keeps the step in the contract until the last required party approves it', function () {
    $readyFingerprint = $this->s->readiness()->fingerprint;
    $step = app(ArchiveStep::class)->handle($this->s->alpha, $this->drop);

    // The text's approvals no longer count, so the contract is not ready, and the step is still part of it.
    expect($step->isArchived())->toBeFalse()
        ->and($step->currentRevision->number)->toBe(2)
        ->and($step->currentRevision->proposes_archive)->toBeTrue()
        ->and($step->currentRevision->only(['title', 'description_md']))->toBe(['title' => 'Drop', 'description_md' => 'Not needed.'])
        ->and($this->s->readiness()->isReady())->toBeFalse()
        ->and($this->s->readiness()->fingerprint)->not->toBe($readyFingerprint)
        ->and(ContractPlan::markdown($this->s->contract->fresh(), $this->s->readiness()))->toContain("### 1.2 Drop\n\n_Proposed for archiving");

    // Proposing again is a no-op; a party that is not required does not count.
    app(ArchiveStep::class)->handle($this->s->alpha, $this->drop);
    $this->s->approve($this->s->party(ContractRole::Commenter), $this->drop);
    $this->s->approve($this->s->alpha, $this->drop);
    expect($this->drop->fresh()->isArchived())->toBeFalse()
        ->and($this->drop->fresh()->currentRevision->number)->toBe(2);

    $this->s->approve($this->s->beta, $this->drop);

    expect($this->drop->fresh()->isArchived())->toBeTrue()
        ->and($this->s->readiness()->isReady())->toBeTrue()
        ->and($this->s->contract->activityEvents()->whereIn('type', ['step.archive_proposed', 'step.archived'])->orderBy('position')->get(['type', 'payload'])->toArray())
        ->toBe([
            ['type' => 'step.archive_proposed', 'payload' => ['revision' => 2, 'previous_revision' => 1, 'cancelled_party_ids' => collect([$this->s->alpha->party->id, $this->s->beta->party->id])->sort()->values()->all()]],
            ['type' => 'step.archived', 'payload' => ['approved_revision' => 2]],
        ]);
});

test('any new revision withdraws the proposal: the same text brings back its approvals, new text needs new ones', function () {
    app(ArchiveStep::class)->handle($this->s->alpha, $this->drop);
    $this->s->approve($this->s->beta, $this->drop);

    app(ReviseStep::class)->handle($this->s->alpha, $this->drop, 2, 'Drop', 'Not needed.');
    expect($this->drop->fresh()->currentRevision->proposes_archive)->toBeFalse()
        ->and($this->s->readiness()->isReady())->toBeTrue();

    app(ArchiveStep::class)->handle($this->s->alpha, $this->drop);
    app(ReviseStep::class)->handle($this->s->alpha, $this->drop, 4, 'Drop', 'Needed after all.');
    $this->s->approve($this->s->alpha, $this->drop);
    $this->s->approve($this->s->beta, $this->drop);

    expect($this->drop->fresh()->isArchived())->toBeFalse()
        ->and($this->s->readiness()->isReady())->toBeTrue();
});

test('without required approvers a step is archived at once; losing the last missing approver settles a proposal', function () {
    app(ArchiveStep::class)->handle($this->s->alpha, $this->drop);
    $this->s->approve($this->s->alpha, $this->drop);
    app(SetRequiredApprover::class)->handle($this->s->ownerActor(), $this->s->contract, $this->s->beta->party, false);

    expect($this->drop->fresh()->isArchived())->toBeTrue();

    app(SetRequiredApprover::class)->handle($this->s->ownerActor(), $this->s->contract, $this->s->alpha->party, false);
    app(ArchiveStep::class)->handle($this->s->alpha, $this->keep);

    expect($this->keep->fresh()->isArchived())->toBeTrue()
        ->and($this->keep->fresh()->revisions()->count())->toBe(1);
});

test('a proposal hashes differently from its text, and other hashes are unchanged, so stored sign-offs stay valid', function () {
    expect(StepRevision::contentHash('Build the API', 'Endpoints.'))
        ->toBe(hash('sha256', (string) json_encode(['Build the API', 'Endpoints.'])))
        ->and(StepRevision::contentHash('Build the API', 'Endpoints.', true))->not->toBe(StepRevision::contentHash('Build the API', 'Endpoints.'))
        ->and(StepRevision::contentHash(' Build  the API', "Endpoints.\n", true))->toBe(StepRevision::contentHash('Build the API', 'Endpoints.', true));
});
