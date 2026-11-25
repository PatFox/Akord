<?php

use App\Actions\Checklists\AddChecklistItem;
use App\Actions\Checklists\CreateChecklist;
use App\Actions\Checklists\DeleteChecklistItem;
use App\Actions\Checklists\MoveChecklistItem;
use App\Actions\Checklists\SetChecklistAssignee;
use App\Actions\Checklists\SetChecklistItemChecked;
use App\Actions\Checklists\UpdateChecklistItem;
use App\Actions\Contracts\DetachParty;
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
use App\Support\Actor;
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

test('a stage title that already starts with its number is not numbered again', function () {
    $this->actingAs($this->s->alpha->user);
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team', [
        ['title' => '1. Database', 'items' => []],
        ['title' => '2) Screens', 'items' => []],
        ['title' => 'Webhooks', 'items' => []],
        ['title' => '1.5 kg limit', 'items' => []],
        ['title' => '2. Numbered for another stage', 'items' => []],
    ]);

    Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $checklist->id])
        ->assertSeeInOrder(['1. Database', '2) Screens', '3. Webhooks', '4. 1.5 kg limit', '5. 2. Numbered for another stage'])
        ->assertDontSee('1. 1. Database')
        ->assertDontSee('2. 2) Screens');
});

test('an owner assigns attached parties to a checklist, which then tick its items whatever their role', function () {
    $api = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');
    $app = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'App team', [['title' => 'Screens', 'items' => [['title' => 'Login']]]]);
    $viewer = $this->s->party(ContractRole::Viewer);
    $readOnlyAgent = $this->s->party(ContractRole::Viewer, agent: true, abilities: ['read']);
    $outsider = $this->s->party(null);
    $assign = fn (Actor $as, Actor $party, bool $assigned = true) => status(fn () => app(SetChecklistAssignee::class)->handle($as, $this->s->contract, $api->id, $party->party, $assigned));
    $tick = fn (Actor $as, Checklist $checklist) => status(fn () => app(SetChecklistItemChecked::class)->handle($as, $this->s->contract, $checklist->items()->first()->id, true));

    expect($assign($this->s->alpha, $viewer))->toBe(403)
        ->and($assign($this->s->ownerActor(), $outsider))->toBe(422)
        ->and($tick($viewer, $api))->toBe(403)
        ->and($assign($this->s->ownerActor(), $viewer))->toBe(200)
        ->and($assign($this->s->ownerActor(), $viewer))->toBe(200)
        ->and($assign($this->s->ownerActor(), $readOnlyAgent))->toBe(200)
        ->and($this->s->activityCount('checklist.party_assigned'))->toBe(2)
        ->and($tick($viewer, $api))->toBe(200)
        ->and($tick($viewer, $app))->toBe(403)
        ->and($tick($readOnlyAgent, $api))->toBe(403)
        ->and(ActivityDescription::for(ActivityEvent::query()->where('type', 'checklist.party_assigned')->firstOrFail()))->toContain('to “API team”');

    // Replacing the checklist by name keeps its assignees; detaching the party ends its assignment.
    $replaced = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team', replace: true);
    expect($tick($viewer, $replaced))->toBe(200);
    app(DetachParty::class)->handle($this->s->ownerActor(), $this->s->contract, $viewer->party);
    expect($replaced->assignedParties()->pluck('parties.id')->all())->toBe([$readOnlyAgent->party?->id]);

    // Unassigning takes the right away again; a new version keeps the assignees.
    expect(status(fn () => app(SetChecklistAssignee::class)->handle($this->s->ownerActor(), $this->s->contract, $replaced->id, $readOnlyAgent->party, false)))->toBe(200)
        ->and($replaced->assignedParties()->count())->toBe(0);
    $member = $this->s->party(ContractRole::Commenter);
    app(SetChecklistAssignee::class)->handle($this->s->ownerActor(), $this->s->contract, $replaced->id, $member->party, true);
    $this->s->approveAll($this->one, $this->two);
    $fingerprint = $this->s->readiness()->fingerprint;
    app(SignOffContract::class)->handle($this->s->alpha, $this->s->contract, $fingerprint);
    app(SignOffContract::class)->handle($this->s->beta, $this->s->contract, $fingerprint);
    $next = app(StartNewVersion::class)->handle($this->s->ownerActor(), $this->s->contract);

    expect($next->checklists()->where('name', 'API team')->sole()->assignedParties()->pluck('parties.id')->all())->toBe([$member->party?->id])
        ->and(status(fn () => app(SetChecklistItemChecked::class)->handle($member, $next, $next->checklists()->where('name', 'API team')->sole()->items()->first()->id, true)))->toBe(200);

    // A complete version takes no assignments.
    app(SetContractStatus::class)->handle($this->s->alpha, $next, ContractStatus::Complete);
    expect(status(fn () => app(SetChecklistAssignee::class)->handle($this->s->ownerActor(), $next, $next->checklists()->where('name', 'API team')->sole()->id, $member->party, false)))->toBe(423);
});

test('editors change, reorder and delete items, keeping ticks and closing gaps; the activity says what happened', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team', [
        ['title' => 'Build', 'items' => [['title' => 'Routes', 'notes' => 'REST only.', 'step_id' => $this->one->id], ['title' => 'Tests'], ['title' => 'Docs']]],
        ['title' => 'Ship', 'items' => [['title' => 'Deploy']]],
    ]);
    $other = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'App team', [['title' => 'Screens', 'items' => [['title' => 'Login']]]]);
    $id = fn (string $title) => ChecklistItem::query()->where('title', $title)->sole()->id;
    $layout = fn () => $checklist->items()->orderBy('checklist_stages.position')->orderBy('checklist_items.position')->get()
        ->map(fn (ChecklistItem $item) => $item->stage->title.' '.$item->position.' '.$item->title.($item->isChecked() ? ' ✓' : ''))->all();
    $update = fn (array $changes, ?string $item = null) => status(fn () => app(UpdateChecklistItem::class)->handle($this->s->alpha, $this->s->contract, $item ?? $id('Routes'), $changes));
    $move = fn (string $item, ?string $stage, ?string $after) => status(fn () => app(MoveChecklistItem::class)->handle($this->s->alpha, $this->s->contract, $item, $stage, $after));
    $describe = fn (string $type) => ActivityDescription::for(ActivityEvent::query()->where('type', $type)->latest('position')->firstOrFail());
    $ship = $checklist->stages()->where('title', 'Ship')->sole();
    app(SetChecklistItemChecked::class)->handle($this->s->alpha, $this->s->contract, $id('Routes'), true);

    // Only the fields given change; empty notes clear them; the tick stays; repeating it records nothing.
    expect($update(['title' => 'API routes', 'notes' => '', 'step_id' => $this->two->id]))->toBe(200);
    $routes = ChecklistItem::query()->where('title', 'API routes')->sole();
    expect([$routes->notes, $routes->step_id, $routes->isChecked()])->toBe([null, $this->two->id, true])
        ->and($update(['title' => 'API routes'], $routes->id))->toBe(200)
        ->and($this->s->activityCount('checklist.item_updated'))->toBe(1)
        ->and(ActivityEvent::query()->where('type', 'checklist.item_updated')->sole()->payload)->toBe(['fields' => ['title', 'notes', 'step_id']])
        ->and($describe('checklist.item_updated'))->toContain('changed “API routes” in “API team”')
        ->and($update([], $routes->id))->toBe(422)
        ->and($update(['step_id' => 'nope'], $routes->id))->toBe(422);

    // Reorder within a stage, move to another stage of the same checklist; positions stay 1..n.
    expect($move($id('Docs'), null, 'start'))->toBe(200)
        ->and($move($routes->id, $ship->id, $id('Deploy')))->toBe(200)
        ->and($layout())->toBe(['Build 1 Docs', 'Build 2 Tests', 'Ship 1 Deploy', 'Ship 2 API routes ✓'])
        ->and($move($id('Deploy'), null, null))->toBe(200)
        ->and($layout())->toBe(['Build 1 Docs', 'Build 2 Tests', 'Ship 1 API routes ✓', 'Ship 2 Deploy'])
        ->and($describe('checklist.item_moved'))->toContain('moved “Deploy” in “API team”')
        ->and($move($id('Tests'), null, $id('Tests')))->toBe(422)
        ->and($move($id('Tests'), null, $id('Deploy')))->toBe(422)
        ->and($move($id('Tests'), $other->stages()->sole()->id, null))->toBe(422);

    // Deleting closes the gap and keeps the title for the activity description.
    app(DeleteChecklistItem::class)->handle($this->s->alpha, $this->s->contract, $id('Docs'));
    expect($layout())->toBe(['Build 1 Tests', 'Ship 1 API routes ✓', 'Ship 2 Deploy'])
        ->and($describe('checklist.item_deleted'))->toBe($this->s->alpha->user?->name.' deleted “Docs” from “API team”');
});

test('only the edit ability changes, moves or deletes items: not commenters, assigned viewers or read-only agents; closed versions refuse', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');
    $itemId = $checklist->items()->first()->id;
    $viewer = $this->s->party(ContractRole::Viewer);
    app(SetChecklistAssignee::class)->handle($this->s->ownerActor(), $this->s->contract, $checklist->id, $viewer->party, true);
    $readOnlyAgent = $this->s->party(ContractRole::Editor, agent: true, abilities: ['read']);
    $elsewhere = Scenario::make();
    $foreign = app(CreateChecklist::class)->handle($elsewhere->alpha, $elsewhere->contract, 'Elsewhere', [['title' => 'Theirs', 'items' => [['title' => 'Not yours']]]]);
    $all = fn (Actor $as, string $item) => [
        status(fn () => app(UpdateChecklistItem::class)->handle($as, $this->s->contract, $item, ['title' => 'New'])),
        status(fn () => app(MoveChecklistItem::class)->handle($as, $this->s->contract, $item, null, 'start')),
        status(fn () => app(DeleteChecklistItem::class)->handle($as, $this->s->contract, $item)),
    ];

    expect($all($this->s->beta, $itemId))->toBe([403, 403, 403])
        ->and($all($viewer, $itemId))->toBe([403, 403, 403])
        ->and($all($readOnlyAgent, $itemId))->toBe([403, 403, 403])
        ->and($all($this->s->alpha, $foreign->items()->first()->id))->toBe([404, 404, 404]);

    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Complete);
    expect($all($this->s->alpha, $itemId))->toBe([423, 423, 423]);
});

test('owners assign from the checklist tab; an assigned viewer ticks there', function () {
    $api = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team');
    $viewer = $this->s->party(ContractRole::Viewer);

    $this->actingAs($viewer->user);
    $before = Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $api->id])
        ->assertDontSeeHtml('data-test="checklist-assignees"');
    expect($before->instance()->canTick)->toBeFalse();

    $this->actingAs($this->s->owner);
    Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $api->id])
        ->set('assignPartyId', $viewer->party->id)->call('assign')
        ->assertSeeInOrder(['Assigned:', $viewer->party->name]);

    $this->actingAs($viewer->user);
    $after = Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $api->id]);
    expect($after->instance()->canTick)->toBeTrue();
    $after->call('toggle', $api->items()->first()->id)->assertSee('1 of 2 done');
});

test('editors change, reorder, move and delete items from the checklist tab; viewers get no item actions', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team', [
        ['title' => 'Build', 'items' => [['title' => 'Routes', 'notes' => 'REST only.'], ['title' => 'Tests']]],
        ['title' => 'Ship', 'items' => [['title' => 'Deploy']]],
    ]);
    $id = fn (string $title) => ChecklistItem::query()->where('title', $title)->sole()->id;
    $layout = fn () => $checklist->items()->orderBy('checklist_stages.position')->orderBy('checklist_items.position')->get()
        ->map(fn (ChecklistItem $item) => $item->stage->title.' '.$item->title)->all();
    $tab = fn () => Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $checklist->id]);

    $this->actingAs($this->s->party(ContractRole::Viewer)->user);
    $tab()->assertDontSeeHtml('data-test="item-actions"');

    $this->actingAs($this->s->alpha->user);
    $page = $tab()->assertSeeHtml('data-test="item-actions"')
        ->call('editItem', $id('Routes'))->assertSet('itemTitle', 'Routes')->assertSet('itemNotes', 'REST only.')
        ->set('itemTitle', '  ')->call('saveItem')->assertHasErrors('itemTitle')
        ->set('itemTitle', 'API routes')->set('itemNotes', '')->set('itemStepId', $this->one->id)->call('saveItem')
        ->assertHasNoErrors()->assertSet('editingItemId', null)->assertSee('API routes')->assertSee('Step: Endpoints')->assertDispatched('checklist-updated');
    expect(ChecklistItem::query()->whereKey($id('API routes'))->value('notes'))->toBeNull();

    $page->call('moveItem', $id('Tests'), -1);
    expect($layout())->toBe(['Build Tests', 'Build API routes', 'Ship Deploy']);

    $page->call('moveItem', $id('Tests'), 1);
    expect($layout())->toBe(['Build API routes', 'Build Tests', 'Ship Deploy']);

    $page->call('moveItemTo', $id('API routes'), $checklist->stages()->where('title', 'Ship')->sole()->id);
    expect($layout())->toBe(['Build Tests', 'Ship Deploy', 'Ship API routes']);

    $page->call('editItem', $id('Deploy'))->call('deleteItem', $id('Deploy'))
        ->assertSet('editingItemId', null)->assertDontSee('Deploy')->assertSee('0 of 2 done');
    expect($layout())->toBe(['Build Tests', 'Ship API routes']);
});

test('editors add items at the end of a stage from the checklist tab, several in a row; viewers get no add control', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, 'API team', [
        ['title' => 'Build', 'items' => [['title' => 'Routes']]],
        ['title' => 'Ship', 'items' => []],
    ]);
    $ship = $checklist->stages()->where('title', 'Ship')->sole();
    $tab = fn () => Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract, 'checklistId' => $checklist->id]);

    $this->actingAs($this->s->party(ContractRole::Viewer)->user);
    $tab()->assertDontSeeHtml('data-test="add-item"');

    $this->actingAs($this->s->alpha->user);
    $page = $tab()->assertSeeHtml('data-test="add-item"')
        ->call('startAddingItem', $ship->id)->assertSeeHtml('data-test="item-adder"')
        ->set('newItemTitle', ' ')->call('addItem')->assertHasErrors('newItemTitle')
        ->set('newItemTitle', 'Deploy')->set('newItemNotes', 'Blue-green.')->set('newItemStepId', $this->two->id)->call('addItem')
        ->assertHasNoErrors()->assertSet('addingToStageId', $ship->id)->assertSet('newItemTitle', '')->assertDispatched('checklist-updated')
        ->set('newItemTitle', 'Announce')->call('addItem')
        ->assertSee('0 of 3 done')
        ->call('cancelAddItem')->assertDontSeeHtml('data-test="item-adder"');

    expect($ship->items()->get()->map(fn (ChecklistItem $item) => [$item->position, $item->title, $item->notes, $item->step_id])->all())
        ->toBe([[1, 'Deploy', 'Blue-green.', $this->two->id], [2, 'Announce', null, null]])
        ->and($this->s->activityCount('checklist.item_added'))->toBe(2);

    // Closing the version closes the form.
    $page->call('startAddingItem', $ship->id);
    app(SetContractStatus::class)->handle($this->s->alpha, $this->s->contract, ContractStatus::Complete);
    $page->call('$refresh')->assertSet('addingToStageId', null)->assertDontSeeHtml('data-test="add-item"');
});
