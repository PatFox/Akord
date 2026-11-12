<?php

use App\Enums\ContractRole;
use App\Enums\OrganisationRole;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;

/**
 * Org A: owner, alice (individual party on c1), bob (team party on no contract), an agent on c2.
 * Org B: its own owner, carol on cB, and alice invited to cB through an org B party.
 */
beforeEach(function () {
    $this->orgA = Organisation::factory()->create();
    $this->orgB = Organisation::factory()->create();

    $this->ownerA = User::factory()->create();
    $this->ownerB = User::factory()->create();
    $this->orgA->users()->attach($this->ownerA, ['role' => OrganisationRole::Owner->value]);
    $this->orgB->users()->attach($this->ownerB, ['role' => OrganisationRole::Owner->value]);

    $this->projectA1 = Project::factory()->for($this->orgA)->create();
    $this->projectA2 = Project::factory()->for($this->orgA)->create();
    $this->projectA3 = Project::factory()->for($this->orgA)->create();
    $this->projectB = Project::factory()->for($this->orgB)->create();

    $this->c1 = Contract::factory()->for($this->projectA1)->create();
    $this->c2 = Contract::factory()->for($this->projectA1)->create();
    $this->c3 = Contract::factory()->for($this->projectA3)->create();
    $this->cB = Contract::factory()->for($this->projectB)->create();

    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
    $this->carol = User::factory()->create();

    $aliceParty = Party::factory()->for($this->orgA)->create();
    $aliceParty->users()->attach($this->alice);
    $this->c1->parties()->attach($aliceParty, ['role' => ContractRole::Viewer->value]);

    Party::factory()->for($this->orgA)->team()->create()->users()->attach($this->bob);

    $this->agent = Party::factory()->for($this->orgA)->agent()->create();
    $this->c2->parties()->attach($this->agent, ['role' => ContractRole::Editor->value, 'is_required_approver' => true]);

    $partyB = Party::factory()->for($this->orgB)->create();
    $partyB->users()->attach([$this->carol->id, $this->alice->id]);
    $this->cB->parties()->attach($partyB, ['role' => ContractRole::Commenter->value]);
});

function visibleContractIds(User|Party $actor): array
{
    return Contract::query()->visibleTo($actor)->orderBy('id')->pluck('id')->all();
}

function visibleProjectIds(User|Party $actor): array
{
    return Project::query()->visibleTo($actor)->orderBy('id')->pluck('id')->all();
}

test('an organisation owner sees every contract in their organisation and nothing in others', function () {
    expect(visibleContractIds($this->ownerA))->toBe([$this->c1->id, $this->c2->id, $this->c3->id])
        ->and(visibleContractIds($this->ownerB))->toBe([$this->cB->id]);
});

test('a non-owner sees only contracts attached to a party they belong to, across organisations', function () {
    expect(visibleContractIds($this->alice))->toBe([$this->c1->id, $this->cB->id])
        ->and(visibleContractIds($this->carol))->toBe([$this->cB->id]);
});

test('belonging to a party that is attached to no contract grants nothing', function () {
    expect(visibleContractIds($this->bob))->toBe([]);
});

test('an agent sees only the contracts it is attached to', function () {
    expect(visibleContractIds($this->agent))->toBe([$this->c2->id]);
});

test('owners see every project in their organisation, including empty ones', function () {
    expect(visibleProjectIds($this->ownerA))
        ->toBe([$this->projectA1->id, $this->projectA2->id, $this->projectA3->id]);
});

test('non-owners see only projects that hold a contract visible to them', function () {
    expect(visibleProjectIds($this->alice))->toBe([$this->projectA1->id, $this->projectB->id])
        ->and(visibleProjectIds($this->agent))->toBe([$this->projectA1->id])
        ->and(visibleProjectIds($this->bob))->toBe([]);
});
