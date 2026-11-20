<?php

use App\Actions\Checklists\AddChecklistItem;
use App\Actions\Checklists\CreateChecklist;
use App\Actions\Checklists\SetChecklistItemChecked;
use App\Actions\Contracts\SetContractStatus;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\StartNewVersion;
use App\Enums\ContractRole;
use App\Enums\ContractStatus;
use App\Models\ActivityEvent;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Party;
use App\Support\ActivityDescription;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->one = $this->s->step('Endpoints', 'List them.');
    $this->two = $this->s->step('Errors', 'Problem details.');
});

function checklistItems(Checklist $checklist): array
{
    return $checklist->items()->orderBy('checklist_stages.position')->orderBy('checklist_items.position')->get()
        ->map(fn (ChecklistItem $item) => [$item->stage->title, $item->title, $item->step_id, $item->isChecked()])->all();
}

test('without stages the checklist copies the phases and steps, linked to them', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');

    expect(checklistItems($checklist))->toBe([
        ['Phase one', 'Endpoints', $this->one->id, false],
        ['Phase one', 'Errors', $this->two->id, false],
    ])->and($this->s->activityCount('checklist.created'))->toBe(1);
});

test('an agent writes its own stages; ticking records who; replacing ticked work needs replace', function () {
    $agent = $this->s->party(ContractRole::Editor, agent: true, abilities: ['read', 'edit']);
    $stages = [
        ['title' => 'Build', 'items' => [['title' => 'Routes', 'step_id' => $this->one->id], ['title' => 'Tests', 'notes' => 'Feature tests.']]],
        ['title' => 'Ship', 'items' => []],
    ];
    $checklist = app(CreateChecklist::class)->handle($agent, $this->s->contract, 'API team', $stages);
    $routes = $checklist->items()->where('checklist_items.title', 'Routes')->sole();
    app(SetChecklistItemChecked::class)->handle($agent, $this->s->contract, $routes->id, true);
    app(SetChecklistItemChecked::class)->handle($agent, $this->s->contract, $routes->id, true);

    expect($routes->fresh()?->only(['checked_by_party_id', 'checked_by_user_id']))->toBe(['checked_by_party_id' => $agent->party?->id, 'checked_by_user_id' => null])
        ->and($this->s->activityCount('checklist.item_checked'))->toBe(1)
        ->and(status(fn () => app(CreateChecklist::class)->handle($agent, $this->s->contract, 'api TEAM')))->toBe(422);

    // The same name, ignoring case, is the same checklist: replacing it needs replace.
    app(CreateChecklist::class)->handle($agent, $this->s->contract, 'api TEAM', replace: true);
    expect(Checklist::query()->pluck('name')->all())->toBe(['api TEAM'])->and(ChecklistItem::query()->count())->toBe(2);
});

test('a contract has several named checklists, each with its own items, copied into a new version', function () {
    $api = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, '  API   team ');
    $app = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'App team', [['title' => 'Screens', 'items' => [['title' => 'Login']]]]);
    app(SetChecklistItemChecked::class)->handle($this->s->alpha, $this->s->contract, $app->items()->sole()->id, true);

    expect($api->name)->toBe('API team')
        ->and(checklistItems($api))->toHaveCount(2)
        ->and(checklistItems($app))->toBe([['Screens', 'Login', null, true]])
        ->and(ActivityDescription::for(ActivityEvent::query()->where('type', 'checklist.item_checked')->sole()))->toContain('“Login” in “App team”')
        ->and(status(fn () => app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, '   ')))->toBe(422);

    $this->s->approveAll($this->one, $this->two);
    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);
    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    expect($next->checklists()->orderBy('created_at')->orderBy('name')->get()->map(fn (Checklist $c) => [$c->name, $c->items()->whereNotNull('checked_at')->count()])->all())
        ->toBe([['API team', 0], ['App team', 1]]);
});

test('a step link must be an active step of this contract', function () {
    $other = Scenario::make();
    $foreign = $other->step('Elsewhere');

    expect(status(fn () => app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team', [['title' => 'A', 'items' => [['title' => 'x', 'step_id' => $foreign->id]]]])))->toBe(422)
        ->and(Checklist::query()->count())->toBe(0);
});

test('editors and owners tick; commenters and read-only agents cannot; items of another contract are not found', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');
    $item = $checklist->items()->first();
    $readOnly = $this->s->party(ContractRole::Editor, agent: true, abilities: ['read']);
    $ownParty = Party::factory()->for($this->s->organisation)->create();
    $ownParty->users()->attach($this->s->owner);
    $other = Scenario::make();

    expect(status(fn () => app(SetChecklistItemChecked::class)->handle($this->s->beta, $this->s->contract, $item->id, true)))->toBe(403)
        ->and(status(fn () => app(SetChecklistItemChecked::class)->handle($readOnly, $this->s->contract, $item->id, true)))->toBe(403)
        ->and(status(fn () => app(SetChecklistItemChecked::class)->handle($this->s->ownerActor($ownParty), $this->s->contract, $item->id, true)))->toBe(200)
        ->and(status(fn () => app(SetChecklistItemChecked::class)->handle($other->alpha, $other->contract, $item->id, false)))->toBe(404);
});

test('ticking works after agreement, not once complete; a new version keeps the checklist and its ticks', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');
    $this->s->approveAll($this->one, $this->two);
    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);

    $first = $checklist->items()->orderBy('checklist_items.position')->first();
    app(SetChecklistItemChecked::class)->handle($this->s->alpha, $this->s->contract, $first->id, true);
    app(AddChecklistItem::class)->handle($this->s->alpha, $this->s->contract, $first->checklist_stage_id, 'Found later');
    expect($this->s->contract->fresh()?->isAgreed())->toBeTrue()
        ->and($this->s->readiness()->signedOffPartyIds)->toHaveCount(2);

    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Complete);
    expect(status(fn () => app(SetChecklistItemChecked::class)->handle($this->s->alpha, $this->s->contract, $first->id, false)))->toBe(423);

    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);
    $newSteps = $next->steps()->orderBy('phases.position')->orderBy('steps.position')->pluck('steps.id')->all();

    expect(checklistItems($next->checklists()->sole()))->toBe([
        ['Phase one', 'Endpoints', $newSteps[0], true],
        ['Phase one', 'Errors', $newSteps[1], false],
        ['Phase one', 'Found later', null, false],
    ]);
});

test('each checklist gets its own tab, "Checklist: name", with its count; people tick from it', function () {
    $this->actingAs($this->s->alpha->user);
    Livewire::test('pages::contracts.show', ['contract' => $this->s->contract])->assertDontSeeHtml('data-test="tab-checklist"');

    $api = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');
    app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'App team', [['title' => 'Screens', 'items' => [['title' => 'Login']]]]);
    $page = Livewire::withQueryParams(['tab' => 'checklist-'.$api->id])->test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertSeeInOrder(['Checklist: API team', '0/2', 'Checklist: App team', '0/1'])
        ->assertSet('tab', 'checklist-'.$api->id);

    Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $api->id])
        ->assertSee('API team')->assertDontSee('Login')
        ->call('toggle', $api->items()->first()->id)
        ->assertDispatched('checklist-updated')
        ->assertSee('1 of 2 done');

    $page->dispatch('checklist-updated')->assertSee('1/2');

    // Another contract's checklist, or an unknown tab, falls back to the Steps tab.
    $elsewhere = Scenario::make();
    $other = app(CreateChecklist::class)->handle($elsewhere->alpha, $elsewhere->contract, 'Elsewhere');
    Livewire::withQueryParams(['tab' => 'checklist-'.$other->id])->test('pages::contracts.show', ['contract' => $this->s->contract])->assertSet('tab', 'steps');

    $this->actingAs($this->s->beta->user);
    Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $api->id])->assertSeeHtml('disabled');
});
