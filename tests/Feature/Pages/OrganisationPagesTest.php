<?php

use App\Enums\PartyType;
use App\Models\Contract;
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
