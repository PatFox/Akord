<?php

use App\Enums\ContractStatus;
use App\Enums\PartyType;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use Livewire\Livewire;
use Tests\Support\Scenario;

test('creating an organisation makes the creator its owner with a personal party', function () {
    $user = User::factory()->create(['name' => 'Dana']);
    $this->actingAs($user);

    Livewire::test('pages::organisations.index')
        ->set('name', 'Dana Labs')
        ->call('create')
        ->assertRedirect(route('organisations.show', 'dana-labs'));

    $organisation = Organisation::query()->where('slug', 'dana-labs')->firstOrFail();
    expect($user->isOwnerOf($organisation->id))->toBeTrue()
        ->and(Party::query()->where('organisation_id', $organisation->id)->whereRelation('users', 'users.id', $user->id)->pluck('name')->all())->toBe(['Dana']);
});

test('owners create projects, contracts and parties from the pages', function () {
    $s = Scenario::make();
    $this->actingAs($s->owner);

    Livewire::test('pages::organisations.show', ['organisation' => $s->organisation])
        ->set('projectName', 'Web app')->call('createProject');
    $project = Project::query()->where('name', 'Web app')->firstOrFail();

    Livewire::test('pages::projects.show', ['organisation' => $s->organisation, 'project' => $project])
        ->set('title', 'Checkout')->set('requireHumanSignoff', true)->call('create');

    Livewire::test('pages::organisations.parties', ['organisation' => $s->organisation])
        ->set('name', 'QA')->set('type', 'team')->set('joinAsMember', true)->call('create');

    expect(Contract::query()->where('project_id', $project->id)->first()?->require_human_signoff)->toBeTrue()
        ->and(Party::query()->where('name', 'QA')->first()?->type)->toBe(PartyType::Team)
        ->and(Party::query()->where('name', 'QA')->first()?->users->pluck('id')->all())->toBe([$s->owner->id]);
});

test('members cannot create projects in an organisation they do not own', function () {
    $s = Scenario::make();
    $this->actingAs($s->alpha->user);

    Livewire::test('pages::organisations.show', ['organisation' => $s->organisation])
        ->assertDontSee('Create project')
        ->set('projectName', 'Sneaky')->call('createProject');

    expect(Project::query()->where('name', 'Sneaky')->exists())->toBeFalse();
});

test('the contracts list shows each contract once, as its latest visible version, and only open ones unless asked', function () {
    $s = Scenario::make();
    $project = $s->contract->project;
    $v2 = Contract::factory()->for($project)->create(['title' => $s->contract->title, 'version' => 2, 'previous_version_id' => $s->contract->id]);
    ContractParty::query()->create(['contract_id' => $v2->id, 'party_id' => $s->alpha->party?->id, 'role' => 'editor']);
    Contract::factory()->for($project)->create(['title' => 'Done deal', 'status' => ContractStatus::Complete]);
    Contract::factory()->for($project)->create(['title' => 'Dropped', 'status' => ContractStatus::Cancelled]);

    $rows = function (?string $status, User $user) use ($s, $project): array {
        test()->actingAs($user);
        $page = Livewire::withQueryParams($status === null ? [] : ['status' => $status])
            ->test('pages::projects.show', ['organisation' => $s->organisation, 'project' => $project]);

        return $page->instance()->contracts->map(fn (array $row) => $row['contract']->title.' v'.$row['contract']->version)->all();
    };
    $title = $s->contract->title;

    expect($rows(null, $s->owner))->toBe(["{$title} v2"])
        ->and($rows('complete', $s->owner))->toBe(['Done deal v1'])
        ->and($rows('all', $s->owner))->toEqualCanonicalizing(["{$title} v2", 'Done deal v1', 'Dropped v1'])
        ->and($rows('bogus', $s->owner))->toBe(["{$title} v2"])
        // Beta is not on version 2, so sees version 1 as the contract's row.
        ->and($rows(null, $s->beta->user))->toBe(["{$title} v1"])
        ->and(Livewire::actingAs($s->owner)->test('pages::organisations.show', ['organisation' => $s->organisation])->instance()->projects->first()['open'])->toBe(1);
});
