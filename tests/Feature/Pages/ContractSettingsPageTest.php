<?php

use App\Actions\Contracts\SignOffContract;
use App\Enums\ContractRole;
use App\Models\ContractParty;
use App\Models\Party;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->actingAs($this->s->owner);
});

function settingsPage(Scenario $s)
{
    return Livewire::test('pages::contracts.settings', ['contract' => $s->contract]);
}

function membershipOf(Scenario $s, Party $party): ?ContractParty
{
    return ContractParty::query()->where('contract_id', $s->contract->id)->where('party_id', $party->id)->first();
}

test('owners attach parties, change roles and set required approvers', function () {
    $qa = Party::factory()->for($this->s->organisation)->create(['name' => 'QA']);

    settingsPage($this->s)
        ->set('attachPartyId', $qa->id)->set('attachRole', 'viewer')->call('attach')
        ->set("roles.{$qa->id}", 'commenter')
        ->call('setRequired', $qa->id, true);

    expect(membershipOf($this->s, $qa)?->only(['role', 'is_required_approver']))
        ->toBe(['role' => ContractRole::Commenter, 'is_required_approver' => true]);
});

test('a required approver cannot be detached until it is no longer required', function () {
    $beta = $this->s->beta->party;

    settingsPage($this->s)->call('detach', $beta->id);
    expect(membershipOf($this->s, $beta))->not->toBeNull();

    settingsPage($this->s)->call('setRequired', $beta->id, false)->call('detach', $beta->id);
    expect(membershipOf($this->s, $beta))->toBeNull();
});

test('the human sign-off rule is saved from the switch', function () {
    settingsPage($this->s)->set('requireHumanSignoff', true);

    expect($this->s->contract->fresh()?->require_human_signoff)->toBeTrue();
});

test('an agreed contract continues as a new version from its settings', function () {
    $this->s->approveAll($this->s->step());
    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);

    settingsPage($this->s)->call('startNewVersion')->assertRedirect();

    expect($this->s->contract->nextVersion()->value('version'))->toBe(2);
});
