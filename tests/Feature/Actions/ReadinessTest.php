<?php

use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Steps\ArchiveStep;
use App\Enums\ContractState;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
});

test('every active step approved by every required party is ready for sign-off', function () {
    $one = $this->s->step('One');
    $two = $this->s->step('Two');
    $this->s->approveAll($one, $two);

    $report = $this->s->readiness();

    expect($report->state)->toBe(ContractState::ReadyForSignoff)
        ->and($report->outstanding)->toBe([])
        ->and($this->s->activityCount('contract.ready'))->toBe(1);
});

test('a missing approval leaves the contract in draft and lists what is outstanding', function () {
    $one = $this->s->step('One');
    $two = $this->s->step('Two');
    $this->s->approveAll($one);
    $this->s->approve($this->s->alpha, $two);

    $report = $this->s->readiness();

    expect($report->state)->toBe(ContractState::Draft)
        ->and($report->outstanding)->toBe([['step_id' => $two->id, 'party_id' => $this->s->beta->party?->id]])
        ->and(status(fn () => app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $report->fingerprint)))->toBe(422);
});

test('a contract without active steps is draft', function () {
    $step = $this->s->step();
    $this->s->approveAll($step);
    app(ArchiveStep::class)->handle($this->s->alpha, $step);

    expect($this->s->readiness()->state)->toBe(ContractState::Draft)
        ->and(status(fn () => app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $this->s->readiness()->fingerprint)))->toBe(422);
});

test('a contract without required approvers is never ready', function () {
    $step = $this->s->step();
    foreach ([$this->s->alpha, $this->s->beta] as $actor) {
        app(SetRequiredApprover::class)->handle($this->s->ownerActor(), $this->s->contract, $actor->party, false);
    }

    expect($this->s->readiness()->state)->toBe(ContractState::Draft)
        ->and($this->s->readiness()->outstanding)->toBe([]);
});
