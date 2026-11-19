<?php

use App\Actions\Checklists\AddChecklistItem;
use App\Actions\Checklists\CreateChecklist;
use App\Actions\Checklists\SetChecklistItemChecked;
use App\Actions\Contracts\SetContractStatus;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\StartNewVersion;
use App\Enums\ContractRole;
use App\Enums\ContractStatus;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Party;
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
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract);

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
    $checklist = app(CreateChecklist::class)->handle($agent, $this->s->contract, $stages);
    $routes = $checklist->items()->where('checklist_items.title', 'Routes')->sole();
    app(SetChecklistItemChecked::class)->handle($agent, $this->s->contract, $routes->id, true);
    app(SetChecklistItemChecked::class)->handle($agent, $this->s->contract, $routes->id, true);

    expect($routes->fresh()?->only(['checked_by_party_id', 'checked_by_user_id']))->toBe(['checked_by_party_id' => $agent->party?->id, 'checked_by_user_id' => null])
        ->and($this->s->activityCount('checklist.item_checked'))->toBe(1)
        ->and(status(fn () => app(CreateChecklist::class)->handle($agent, $this->s->contract)))->toBe(422);

    app(CreateChecklist::class)->handle($agent, $this->s->contract, replace: true);
    expect(Checklist::query()->count())->toBe(1)->and(ChecklistItem::query()->count())->toBe(2);
});

test('a step link must be an active step of this contract', function () {
    $other = Scenario::make();
    $foreign = $other->step('Elsewhere');

    expect(status(fn () => app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract, [['title' => 'A', 'items' => [['title' => 'x', 'step_id' => $foreign->id]]]])))->toBe(422)
        ->and(Checklist::query()->count())->toBe(0);
});

test('editors and owners tick; commenters and read-only agents cannot; items of another contract are not found', function () {
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract);
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
    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract);
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

    expect(checklistItems($next->checklist()->sole()))->toBe([
        ['Phase one', 'Endpoints', $newSteps[0], true],
        ['Phase one', 'Errors', $newSteps[1], false],
        ['Phase one', 'Found later', null, false],
    ]);
});

test('the Checklist tab appears once there is a checklist, with its count, and people tick from it', function () {
    $this->actingAs($this->s->alpha->user);
    Livewire::test('pages::contracts.show', ['contract' => $this->s->contract])->assertDontSeeHtml('data-test="tab-checklist"');

    $checklist = app(CreateChecklist::class)->handle($this->s->alpha, $this->s->contract);
    $page = Livewire::withQueryParams(['tab' => 'checklist'])->test('pages::contracts.show', ['contract' => $this->s->contract])
        ->assertSeeHtml('data-test="tab-checklist"')->assertSee('0/2');

    Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract])
        ->call('toggle', $checklist->items()->first()->id)
        ->assertDispatched('checklist-updated')
        ->assertSee('1 of 2 done');

    $page->dispatch('checklist-updated')->assertSee('1/2');

    $this->actingAs($this->s->beta->user);
    Livewire::test('pages::contracts.checklist', ['contract' => $this->s->contract])->assertSeeHtml('disabled');
});
