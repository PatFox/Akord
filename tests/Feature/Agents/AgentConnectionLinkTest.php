<?php

use App\Actions\Organisations\CreateAgentConnectionLink;
use App\Enums\ContractRole;
use Livewire\Livewire;
use Tests\Support\Scenario;

beforeEach(function () {
    $this->s = Scenario::make();
    $this->agent = $this->s->party(ContractRole::Editor, required: true, agent: true)->party;
});

test('a connection link shows instructions until it is used, then gives the party’s MCP config exactly once', function () {
    $this->actingAs($this->s->owner);
    $link = Livewire::test('pages::organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent])
        ->call('createLink')
        ->get('newLink');
    expect($link)->toStartWith(url('/connect/'));
    Livewire::test('pages::organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent])
        ->assertDontSee($link)
        ->assertSee('Waiting to be used');
    auth()->logout();

    // Reading the link (as a link preview would) does not use it up.
    foreach ([1, 2] as $read) {
        $this->get($link)->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee($this->agent->name)
            ->assertSee("curl -sS -X POST {$link}", false);
    }

    $this->post($link)->assertOk()->assertExactJson([
        'mcpServers' => ['akord' => ['type' => 'http', 'url' => url("/mcp/agents/{$this->agent->id}")]],
    ]);

    $this->post($link)->assertStatus(410)->assertJsonPath('message', fn (string $message) => str_contains($message, 'already been used'));
    $this->get($link)->assertStatus(410)->assertSee('already been used');
});

test('expired and unknown connection links are refused', function () {
    [, $link] = app(CreateAgentConnectionLink::class)->handle($this->s->owner, $this->agent);
    $this->travel((int) config('akord.agents.connection_link_hours') + 1)->hours();

    $this->get($link)->assertStatus(410)->assertSee('expired');
    $this->post($link)->assertStatus(410);

    $unknown = url('/connect/'.str_repeat('a', 40));
    $this->get($unknown)->assertNotFound()->assertSee('Unknown connection link');
    $this->post($unknown)->assertNotFound()->assertJsonStructure(['message']);
});

test('only owners create connection links, and only for agent parties', function () {
    expect(status(fn () => app(CreateAgentConnectionLink::class)->handle($this->s->alpha->user, $this->agent)))->toBe(403)
        ->and(status(fn () => app(CreateAgentConnectionLink::class)->handle($this->s->owner, $this->s->alpha->party)))->toBe(422)
        ->and($this->agent->connectionLinks()->count())->toBe(0);
});
