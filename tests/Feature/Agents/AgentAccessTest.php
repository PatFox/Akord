<?php

use App\Actions\Organisations\CreateAgentClaimCode;
use App\Actions\Organisations\RevokeAgentToken;
use App\Actions\Organisations\SetAgentAbilities;
use App\Enums\ContractRole;
use App\Models\Organisation;
use App\Models\Party;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Support\AgentClient;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->agent = $this->s->party(ContractRole::Editor, required: true, agent: true)->party;
});

function claimCode(Scenario $s, Party $agent, array $abilities = ['comment']): string
{
    return app(CreateAgentClaimCode::class)->handle($s->owner, $agent, $abilities)[1];
}

function claim(object $t, string $code): TestResponse
{
    return $t->postJson('/api/v1/agent/claim', ['code' => $code, 'name' => 'ci-bot']);
}

test('a claim code gives a working token once, ignoring case and dashes', function () {
    $code = claimCode($this->s, $this->agent, ['comment']);

    $response = claim($this, strtolower(str_replace('-', '', $code)))->assertCreated()
        ->assertJsonPath('data.party.id', $this->agent->id)
        ->assertJsonPath('data.abilities', ['read', 'comment']);
    $client = new AgentClient($this, $this->agent, $response->json('data.token'));

    $client->api('GET', 'agent')->assertOk()->assertJsonPath('data.party.name', $this->agent->name);
    claim($this, $code)->assertStatus(410);
});

test('expired and unknown claim codes are rejected', function () {
    $code = claimCode($this->s, $this->agent);
    $this->travel((int) config('akord.agents.claim_code_hours') + 1)->hours();

    claim($this, $code)->assertStatus(410);
    claim($this, 'AKD-NOPE0-NOPE0-NOPE0-NOPE0')->assertNotFound();
    expect($this->agent->tokens()->count())->toBe(0);
});

test('a revoked token is refused', function () {
    $client = new AgentClient($this, $this->agent, $this->agent->createToken('t', ['read'])->plainTextToken);
    $client->api('GET', 'contracts')->assertOk();

    app(RevokeAgentToken::class)->handle($this->s->owner, $this->agent, $this->agent->tokens()->sole()->id);

    $client->api('GET', 'contracts')->assertUnauthorized();
});

test('only owners create claim codes, and only for agent parties', function () {
    expect(status(fn () => app(CreateAgentClaimCode::class)->handle($this->s->alpha->user, $this->agent, [])))->toBe(403)
        ->and(status(fn () => app(CreateAgentClaimCode::class)->handle($this->s->owner, $this->s->alpha->party, [])))->toBe(422);
});

test('a token without an ability is refused that action over REST and MCP', function () {
    $step = $this->s->step();
    $readOnly = AgentClient::on($this, $this->s, abilities: ['read', 'comment']);

    $readOnly->api('POST', "contracts/{$this->s->contract->id}/steps/{$step->id}/approval", ['revision' => 1])->assertForbidden();
    expect($readOnly->tool('approve_step', ['contract_id' => $this->s->contract->id, 'step_id' => $step->id, 'revision' => 1]))->toBe([true, "403: This token lacks the 'approve' ability."]);
    $readOnly->api('POST', "contracts/{$this->s->contract->id}/steps/{$step->id}/comments", ['kind' => 'question', 'body_md' => 'Why?'])->assertCreated();
});

test('agents outside a contract get not-found everywhere, and see none of its activity', function () {
    $step = $this->s->step();
    $other = Party::factory()->for(Organisation::factory())->agent()->create();
    $outsider = new AgentClient($this, $other, $other->createToken('t', ['read', 'comment', 'edit', 'approve'])->plainTextToken);
    $id = $this->s->contract->id;

    $outsider->api('GET', "contracts/{$id}")->assertNotFound();
    $outsider->api('GET', "contracts/{$id}/status")->assertNotFound();
    $outsider->api('GET', "contracts/{$id}/steps/{$step->id}")->assertNotFound();
    $outsider->api('POST', "contracts/{$id}/signoffs", ['fingerprint' => str_repeat('a', 64)])->assertNotFound();
    $outsider->api('GET', 'contracts')->assertOk()->assertJsonPath('data', []);
    $outsider->api('GET', 'activity')->assertOk()->assertJsonPath('data.events', []);
    expect($outsider->tool('get_contract', ['contract_id' => $id]))->toBe([true, '404: Not found (or not visible to you).']);
});

test('a signed-in person cannot use the agent API with their browser session', function () {
    $this->actingAs($this->s->owner)->getJson('/api/v1/contracts')->assertUnauthorized();
});

test('owners generate a code that is shown once, and can revoke tokens, from the agent page', function () {
    $this->actingAs($this->s->owner);
    $page = Livewire::test('pages::organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent])
        ->set('abilities', ['approve'])->call('generate');
    $code = $page->get('newCode');

    $token = claim($this, $code)->assertCreated()->json('data');
    expect($token['abilities'])->toBe(['read', 'approve']);

    Livewire::test('pages::organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent])
        ->assertDontSee($code)
        ->assertSee('ci-bot')
        ->call('revoke', $this->agent->tokens()->sole()->id);
    expect($this->agent->tokens()->count())->toBe(0);
});

test('owners change an existing token’s abilities from the agent page, effective on its next request', function () {
    $step = $this->s->step();
    $client = new AgentClient($this, $this->agent, $this->agent->createToken('ci-bot', ['read'])->plainTextToken);
    $tokenId = $this->agent->tokens()->sole()->id;
    $revise = fn () => $client->api('PUT', "contracts/{$this->s->contract->id}/steps/{$step->id}", ['base_revision' => 1, 'title' => 'Build the REST API', 'description_md' => 'Endpoints.']);

    $revise()->assertForbidden();

    $this->actingAs($this->s->owner);
    Livewire::test('pages::organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent])
        ->call('toggleAbility', 'token', $tokenId, 'edit');

    expect($this->agent->tokens()->sole()->abilities)->toBe(['read', 'edit']);
    $revise()->assertSuccessful();

    // Non-owners can't change abilities, and another agent's token is not found.
    $other = $this->s->party(ContractRole::Editor, agent: true)->party;
    $otherToken = $other->createToken('x', ['read']);
    expect(status(fn () => app(SetAgentAbilities::class)->handle($this->s->alpha->user, $this->agent, $this->agent->tokens()->sole(), ['edit'])))->toBe(403)
        ->and(status(fn () => app(SetAgentAbilities::class)->handle($this->s->owner, $this->agent, $otherToken->accessToken, ['edit'])))->toBe(404)
        ->and($otherToken->accessToken->fresh()->abilities)->toBe(['read']);
});

test('only owners open the agent page, and only for agent parties', function () {
    $this->actingAs($this->s->alpha->user)
        ->get(route('organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent]))->assertForbidden();
    $this->actingAs($this->s->owner)
        ->get(route('organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->s->alpha->party]))->assertNotFound();
});

test('requests are rate limited per agent', function () {
    config(['akord.agents.requests_per_minute' => 2]);
    $a = AgentClient::on($this, $this->s);
    $b = AgentClient::on($this, $this->s);

    $a->api('GET', 'contracts')->assertOk();
    $a->api('GET', 'contracts')->assertOk();
    $a->api('GET', 'contracts')->assertTooManyRequests();
    $b->api('GET', 'contracts')->assertOk();
});
