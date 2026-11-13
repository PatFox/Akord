<?php

use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Project;
use Tests\Support\AgentClient;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->agent = AgentClient::on($this, $this->s, required: false, abilities: ['read', 'comment', 'edit', 'approve', 'create']);
    $this->project = $this->s->contract->project_id;
});

test('an agent with the create ability starts a contract it then edits, with no required approvers', function () {
    $response = $this->agent->api('POST', 'contracts', ['project_id' => $this->project, 'title' => 'Webhooks'])->assertCreated()
        ->assertJsonPath('data.content.title', 'Webhooks')
        ->assertJsonPath('data.version', 1)
        ->assertJsonPath('data.state', 'draft')
        ->assertJsonPath('data.your_party.role', 'editor')
        ->assertJsonPath('data.your_party.required_approver', false)
        ->assertJsonPath('data.required_parties', []);
    $id = $response->json('data.id');

    $created = ActivityEvent::query()->where('contract_id', $id)->sole();
    expect($created->only(['type', 'actor_party_id', 'actor_user_id']))->toBe(['type' => 'contract.created', 'actor_party_id' => $this->agent->party->id, 'actor_user_id' => null]);

    // It builds the contract, and even approving every step can't make it ready: an owner picks the required approvers (D4).
    $phase = $this->agent->api('POST', "contracts/{$id}/phases", ['title' => 'Design'])->assertCreated()->json('data.id');
    $step = $this->agent->api('POST', "contracts/{$id}/phases/{$phase}/steps", ['title' => 'Payload format', 'description_md' => ''])->assertCreated()->json('data.id');
    $this->agent->api('POST', "contracts/{$id}/steps/{$step}/approval", ['revision' => 1])->assertOk();
    $this->agent->api('GET', "contracts/{$id}/status")->assertOk()->assertJsonPath('data.state', 'draft');

    // Owners see it; people on the agent's other contract don't.
    expect(Contract::query()->visibleTo($this->s->owner)->whereKey($id)->exists())->toBeTrue()
        ->and(Contract::query()->visibleTo($this->s->alpha->user)->whereKey($id)->exists())->toBeFalse();
});

test('create_contract works as an MCP tool', function () {
    [$error, $text] = $this->agent->tool('create_contract', ['project_id' => $this->project, 'title' => 'Webhooks']);

    expect($error)->toBeFalse()
        ->and($text)->toContain('"title": "Webhooks"')
        ->and(Contract::query()->visibleTo($this->agent->party)->where('title', 'Webhooks')->exists())->toBeTrue();
});

test('creating contracts needs the create ability and a project the agent is already on', function () {
    $noCreate = AgentClient::on($this, $this->s, required: false);
    $sameOrgElsewhere = Project::factory()->for($this->s->organisation)->create();
    $otherOrg = Project::factory()->for(Organisation::factory())->create();
    $before = Contract::query()->count();

    $noCreate->api('POST', 'contracts', ['project_id' => $this->project, 'title' => 'X'])->assertForbidden();
    $this->agent->api('POST', 'contracts', ['project_id' => $sameOrgElsewhere->id, 'title' => 'X'])->assertNotFound();
    $this->agent->api('POST', 'contracts', ['project_id' => $otherOrg->id, 'title' => 'X'])->assertNotFound();
    $this->agent->api('POST', 'contracts', ['project_id' => $this->project])->assertUnprocessable();
    [$error, $text] = $noCreate->tool('create_contract', ['project_id' => $this->project, 'title' => 'X']);

    expect([$error, $text])->toBe([true, "403: This token lacks the 'create' ability."])
        ->and(Contract::query()->count())->toBe($before);
});
