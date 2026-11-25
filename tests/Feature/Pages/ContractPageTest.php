<?php

use App\Actions\Comments\AddComment;
use App\Actions\Contracts\SetContractStatus;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Phases\CreatePhase;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractRole;
use App\Enums\ContractState;
use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\ContractSignoff;
use App\Models\Party;
use Illuminate\Support\Facades\DB;
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

test('editors rename a phase by clicking its title; Escape keeps the old title', function () {
    $page = contractPage($this->s, $this->s->alpha)->assertSeeHtml('data-test="phase-title"')
        ->call('startRenaming', $this->s->phase->id)->set('phaseTitle', 'Discarded')->call('cancelRenaming');
    expect($this->s->phase->fresh()?->title)->toBe('Phase one');

    $page->call('startRenaming', $this->s->phase->id)->set('phaseTitle', 'Discovery')->call('renamePhase')
        ->assertSet('renamingPhase', null)->assertSee('Discovery');
    expect($this->s->phase->fresh()?->title)->toBe('Discovery');

    // Leaving the field after Enter already saved does nothing more.
    $page->call('renamePhase');
    expect($this->s->activityCount('phase.renamed'))->toBe(1);
});

test('each phase shows how many steps are approved, overall and per required party', function () {
    $one = $this->s->step('One');
    $two = $this->s->step('Two');
    $this->s->approveAll($one);
    $this->s->approve($this->s->alpha, $two);

    $counts = contractPage($this->s, $this->s->alpha)->assertSee('1 of 2 approved')->instance()->stageCounts[$this->s->phase->id];

    expect($counts['byParty'])->toEqual([$this->s->alpha->party->id => 2, $this->s->beta->party->id => 1])
        ->and($counts['approved'])->toBe(1);
});

test('the steps panel loads in the same number of queries however many steps it lists', function () {
    // Each round: a step both parties approved, one alpha approved before an edit cancelled it, and one with a comment.
    $addSteps = function (): void {
        $this->s->approveAll($this->s->step('Approved'));
        $edited = $this->s->step('Edited');
        $this->s->approve($this->s->alpha, $edited);
        app(ReviseStep::class)->handle($this->s->alpha, $edited, 1, 'Edited', 'Changed after approval.');
        app(AddComment::class)->handle($this->s->beta, $this->s->step('Commented'), CommentKind::Question, 'Why?');
    };
    $load = function () {
        $this->actingAs($this->s->alpha->user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->get(route('contracts.show', $this->s->contract))->assertOk();
        // The first request creates the session row; later ones update it.
        $queries = collect(DB::getQueryLog())->reject(fn (array $query) => str_contains($query['query'], '"sessions"'))->count();
        DB::disableQueryLog();

        return [$response, $queries];
    };

    $addSteps();
    [, $fewSteps] = $load();
    $addSteps();
    $addSteps();
    [$response, $moreSteps] = $load();

    expect($moreSteps)->toBe($fewSteps)
        ->and(substr_count($response->getContent(), 'data-test="step"'))->toBe(9)
        ->and(substr_count($response->getContent(), 'The step changed after your party approved it.'))->toBe(3)
        ->and(substr_count($response->getContent(), 'title="1 comment"'))->toBe(3);
});

test('the step filter keeps steps missing any required approval, or the acting party\'s; dragging is off while filtering', function () {
    $done = $this->s->step('Done');
    $alphaOnly = $this->s->step('Alpha approved');
    $betaOnly = $this->s->step('Beta approved');
    $this->s->approveAll($done);
    $this->s->approve($this->s->alpha, $alphaOnly);
    $this->s->approve($this->s->beta, $betaOnly);
    $shown = function (string $show, $actor): array {
        test()->actingAs($actor->user);

        return array_keys(Livewire::withQueryParams(['show' => $show])->test('pages::contracts.show', ['contract' => $this->s->contract])->instance()->shownStepIds ?? []);
    };

    expect(contractPage($this->s, $this->s->alpha)->instance()->shownStepIds)->toBeNull()
        ->and($shown('unapproved', $this->s->alpha))->toEqualCanonicalizing([$alphaOnly->id, $betaOnly->id])
        ->and($shown('mine', $this->s->alpha))->toBe([$betaOnly->id])
        ->and($shown('mine', $this->s->beta))->toBe([$alphaOnly->id]);

    $this->actingAs($this->s->alpha->user);
    Livewire::withQueryParams(['show' => 'unapproved'])->test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertDontSeeHtml('wire:sort="sortStep"')
        ->set('show', 'all')->assertSeeHtml('wire:sort="sortStep"');

    // "Awaiting me" needs a required approver; others fall back to all steps.
    $viewer = $this->s->party(ContractRole::Viewer);
    $this->actingAs($viewer->user);
    Livewire::withQueryParams(['show' => 'mine'])->test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertSet('show', 'all')->assertDontSee('Awaiting me');
});

test('when every step is approved, the filter keeps only the phase headers and says so', function () {
    $this->s->approveAll($this->s->step('Only'));

    $this->actingAs($this->s->alpha->user);
    Livewire::withQueryParams(['show' => 'unapproved'])->test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertSee('Every step is approved by every required party.')
        ->assertSeeHtml('data-test="phase-title"')
        ->assertDontSeeHtml('wire:key="step-');
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

test('an owner deletes a cancelled contract from its page and lands on the project; editors see no delete button', function () {
    $this->s->step('Visible step');
    $project = $this->s->contract->project;

    contractPage($this->s, $this->s->alpha)->assertDontSee('Delete contract')
        ->call('setStatus', 'cancelled')->assertSee('Marked cancelled')->assertDontSee('Delete contract');

    $this->actingAs($this->s->owner);
    Livewire::test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertSee('Delete contract')
        ->call('deleteContract')
        ->assertRedirect(route('projects.show', ['organisation' => $project->organisation, 'project' => $project]));

    expect(Contract::query()->whereKey($this->s->contract->id)->exists())->toBeFalse();
});

test('a live update sent in the same request as the delete, after it, still lands on the project', function () {
    $this->s->step('Visible step');
    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Cancelled);
    $statusChanged = $this->s->contract->activityEvents()->where('type', 'contract.status_changed')->sole();
    $project = $this->s->contract->project;

    // Livewire bundles a broadcast's onActivity with the click that is already queued.
    $this->actingAs($this->s->owner);
    Livewire::test('pages::contracts.show', ['contract' => $this->s->contract])
        ->update(calls: [
            ['method' => 'deleteContract', 'params' => [], 'metadata' => []],
            ['method' => 'onActivity', 'params' => [['id' => $statusChanged->id, 'type' => 'contract.status_changed']], 'metadata' => []],
        ])
        ->assertRedirect(route('projects.show', ['organisation' => $project->organisation, 'project' => $project]));

    expect(Contract::query()->whereKey($this->s->contract->id)->exists())->toBeFalse();
});

test('the page carries the deleted-contract dialog; OK leads to the project, or to the dashboard for someone who would no longer see it', function () {
    $project = $this->s->contract->project;
    $projectUrl = route('projects.show', ['organisation' => $project->organisation, 'project' => $project]);
    $viewer = $this->s->party(ContractRole::Viewer);
    $page = function ($user) {
        $this->actingAs($user);

        return Livewire::test('pages::contracts.show', ['contract' => $this->s->contract]);
    };

    $page($this->s->owner)->assertSet('afterDeleteUrl', $projectUrl)->assertSeeHtml('data-test="contract-deleted-dialog"');
    $page($viewer->user)->assertSet('afterDeleteUrl', route('dashboard'));

    // With another contract of theirs in the project, the viewer still sees the project afterwards.
    $other = Contract::factory()->for($project)->create();
    $other->parties()->attach($viewer->party, ['role' => ContractRole::Viewer->value]);
    $page($viewer->user)->assertSet('afterDeleteUrl', $projectUrl);
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
