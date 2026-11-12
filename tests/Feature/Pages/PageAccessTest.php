<?php

use App\Enums\ContractRole;
use App\Models\Party;
use App\Models\User;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->step = $this->s->step();
    $this->organisation = $this->s->organisation;
    $this->project = $this->s->contract->project;
});

function pageUrls(object $t): array
{
    return [
        'organisation' => route('organisations.show', $t->organisation),
        'parties' => route('organisations.parties', $t->organisation),
        'project' => route('projects.show', ['organisation' => $t->organisation, 'project' => $t->project]),
        'contract' => route('contracts.show', $t->s->contract),
        'settings' => route('contracts.settings', $t->s->contract),
        'step' => route('steps.show', ['contract' => $t->s->contract, 'step' => $t->step]),
    ];
}

function statuses(object $t, User $user): array
{
    return collect(pageUrls($t))->map(fn (string $url) => $t->actingAs($user)->get($url)->status())->all();
}

test('people outside the contract get not-found on every page, even in another organisation', function () {
    $outsider = User::factory()->create();
    Party::factory()->create()->users()->attach($outsider);

    expect(statuses($this, $outsider))->toBe([
        'organisation' => 404, 'parties' => 404, 'project' => 404, 'contract' => 404, 'settings' => 404, 'step' => 404,
    ]);
});

test('members see the contract pages but not the owner screens', function () {
    expect(statuses($this, $this->s->beta->user))->toBe([
        'organisation' => 200, 'parties' => 403, 'project' => 200, 'contract' => 200, 'settings' => 403, 'step' => 200,
    ]);
});

test('owners see every page', function () {
    expect(statuses($this, $this->s->owner))->toBe([
        'organisation' => 200, 'parties' => 200, 'project' => 200, 'contract' => 200, 'settings' => 200, 'step' => 200,
    ]);
});

test('a step addressed through another contract is not found', function () {
    $other = Scenario::make();
    $other->party(ContractRole::Viewer);

    $this->actingAs($this->s->owner)
        ->get(route('steps.show', ['contract' => $other->contract, 'step' => $this->step]))
        ->assertNotFound();
});

test('guests are sent to log in', function () {
    $this->get(route('contracts.show', $this->s->contract))->assertRedirect(route('login'));
});
