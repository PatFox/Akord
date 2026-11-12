<?php

use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\WithdrawSignOff;
use App\Enums\ContractRole;
use App\Enums\ContractState;
use App\Models\ContractSignoff;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step();
});

function signOff(Scenario $s, $actor, ?string $fingerprint = null): int
{
    return status(fn () => app(SignOffContract::class)->handle($actor, $s->contract, $fingerprint ?? $s->readiness()->fingerprint));
}

test('when every required party signs off the contract is agreed and locked, once', function () {
    $this->s->approveAll($this->step);

    expect(signOff($this->s, $this->s->alpha))->toBe(200)
        ->and($this->s->readiness()->state)->toBe(ContractState::ReadyForSignoff)
        ->and(signOff($this->s, $this->s->beta))->toBe(200);

    $contract = $this->s->contract->fresh();
    expect($contract?->isAgreed())->toBeTrue()
        ->and($contract?->agreed_fingerprint)->toBe($this->s->readiness()->fingerprint)
        ->and($this->s->readiness()->state)->toBe(ContractState::Agreed)
        ->and($this->s->activityCount('contract.agreed'))->toBe(1);
});

test('a party that is not a required approver cannot sign off', function () {
    $this->s->approveAll($this->step);
    $outsider = $this->s->party(ContractRole::Editor);

    expect(signOff($this->s, $outsider))->toBe(403)
        ->and(ContractSignoff::query()->count())->toBe(0);
});

test('a viewer party cannot sign off', function () {
    $this->s->approveAll($this->step);

    expect(signOff($this->s, $this->s->party(ContractRole::Viewer)))->toBe(403);
});

test('a sign-off with a stale fingerprint is a conflict and records nothing', function () {
    $this->s->approveAll($this->step);
    $stale = $this->s->readiness()->fingerprint;
    $this->s->approveAll($this->s->step('Added after review'));

    expect($this->s->readiness()->state)->toBe(ContractState::ReadyForSignoff)
        ->and(signOff($this->s, $this->s->alpha, $stale))->toBe(409)
        ->and(ContractSignoff::query()->count())->toBe(0);
});

test('signing off twice is a no-op, and a sign-off can be withdrawn before agreement', function () {
    $this->s->approveAll($this->step);
    signOff($this->s, $this->s->alpha);
    signOff($this->s, $this->s->alpha);

    expect(ContractSignoff::query()->active()->count())->toBe(1)
        ->and(app(WithdrawSignOff::class)->handle($this->s->alpha, $this->s->contract))->toBe(1)
        ->and($this->s->readiness()->signedOffPartyIds)->toBe([]);
});

test('D10: with human sign-off required, an agent sign-off is forbidden and records nothing', function () {
    $agent = $this->s->party(ContractRole::Editor, required: true, agent: true);
    $this->s->approveAll($this->step);
    $this->s->approve($agent, $this->step);
    $this->s->contract->update(['require_human_signoff' => true]);

    expect(signOff($this->s, $agent))->toBe(403)
        ->and(ContractSignoff::query()->count())->toBe(0);
});

test('D10: without human sign-off required, an agent can sign off', function () {
    $agent = $this->s->party(ContractRole::Editor, required: true, agent: true);
    $this->s->approveAll($this->step);
    $this->s->approve($agent, $this->step);

    expect(signOff($this->s, $agent))->toBe(200)
        ->and(ContractSignoff::query()->where('party_id', $agent->party?->id)->whereNull('user_id')->count())->toBe(1);
});
