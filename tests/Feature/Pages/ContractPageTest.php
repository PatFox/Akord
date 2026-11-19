<?php

use App\Actions\Contracts\SignOffContract;
use App\Actions\Phases\CreatePhase;
use App\Enums\ContractRole;
use App\Enums\ContractState;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\ContractSignoff;
use App\Models\Party;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
});

function contractPage(Scenario $s, $actor)
{
    test()->actingAs($actor->user);

    return Livewire::test('pages::contracts.show', ['contract' => $s->contract]);
}

test('required parties sign off from the page and the contract becomes agreed', function () {
    $this->s->approveAll($this->s->step());

    contractPage($this->s, $this->s->alpha)->assertSee('Ready for sign-off')->call('signOff');
    contractPage($this->s, $this->s->beta)->call('signOff')->assertSee('Agreed on');

    expect($this->s->readiness()->state)->toBe(ContractState::Agreed);
});

test('signing off what is on screen fails, and records nothing, if the contract changed since it rendered', function () {
    $this->s->approveAll($this->s->step());
    $page = contractPage($this->s, $this->s->alpha);

    $this->s->approveAll($this->s->step('Added meanwhile'));
    $page->call('signOff');

    expect(ContractSignoff::query()->count())->toBe(0)
        ->and($page->get('reviewedFingerprint'))->toBe($this->s->readiness()->fingerprint);
});

test('dragging a step reorders within a phase and moves between phases', function () {
    $one = $this->s->step('One');
    $two = $this->s->step('Two');
    $phaseTwo = app(CreatePhase::class)->handle($this->s->alpha, $this->s->contract, 'Two');

    // Livewire sends this attribute's text unchanged as sortStep's first argument, so it must be the bare id.
    $page = contractPage($this->s, $this->s->alpha)->assertSeeHtml('wire:sort:item="'.$two->id.'"');
    $page->call('sortStep', $two->id, 0, $this->s->phase->id);
    expect($this->s->phase->steps()->pluck('id')->all())->toBe([$two->id, $one->id]);

    $page->call('sortStep', $one->id, 0, $phaseTwo->id);
    expect($one->fresh()?->phase_id)->toBe($phaseTwo->id)
        ->and($this->s->phase->steps()->pluck('id')->all())->toBe([$two->id]);
});

test('a person in two parties chooses which one they act for, but only among their own', function () {
    $user = $this->s->alpha->user;
    $second = Party::factory()->for($this->s->organisation)->create(['name' => 'Second']);
    $second->users()->attach($user);
    $this->s->contract->parties()->attach($second, ['role' => ContractRole::Commenter->value]);
    $stranger = Party::factory()->for($this->s->organisation)->create();
    $this->s->contract->parties()->attach($stranger, ['role' => ContractRole::Editor->value]);

    $page = contractPage($this->s, $this->s->alpha);
    expect($page->get('actingPartyId'))->toBe($this->s->alpha->party->id);

    $page->set('actingPartyId', $second->id);
    expect($page->get('actingPartyId'))->toBe($second->id);

    $page->set('actingPartyId', $stranger->id);
    expect($page->get('actingPartyId'))->toBe($second->id);
});

test('viewers see the contract without editing controls', function () {
    $this->s->step('Visible step');

    contractPage($this->s, $this->s->party(ContractRole::Viewer))
        ->assertSee('Visible step')
        ->assertDontSee('Add step')
        ->assertDontSee('Add phase')
        ->assertDontSee('Sign off contract');
});

test('editors close and reopen the contract from its page, and while closed it is read-only', function () {
    $this->s->step('Visible step');

    contractPage($this->s, $this->s->beta)->call('setStatus', 'cancelled');
    expect($this->s->contract->fresh()?->status)->toBe(ContractStatus::Open);

    contractPage($this->s, $this->s->alpha)
        ->call('setStatus', 'complete')->assertSee('Marked complete')->assertDontSee('Add step')
        ->call('setStatus', 'open')->assertDontSee('Marked complete')->assertSee('Add step');
});

test('an agreed contract continues as a new version from its page; the version menu lists only versions the user can see', function () {
    $this->s->approveAll($this->s->step());
    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);

    $this->actingAs($this->s->owner);
    Livewire::test('pages::contracts.show', ['contract' => $this->s->contract])->call('startNewVersion')->assertRedirect();
    $next = Contract::query()->where('previous_version_id', $this->s->contract->id)->sole();
    // Fixture: beta left the new version.
    ContractParty::query()->where('contract_id', $next->id)->where('party_id', $this->s->beta->party?->id)->delete();

    expect(contractPage($this->s, $this->s->alpha)->instance()->versions->pluck('version')->all())->toBe([1, 2])
        ->and(contractPage($this->s, $this->s->beta)->instance()->versions->pluck('version')->all())->toBe([1]);
});
