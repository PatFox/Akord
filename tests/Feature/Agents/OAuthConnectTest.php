<?php

use App\Actions\Organisations\RevokeAgentConnection;
use App\Enums\ContractRole;
use App\Enums\OrganisationRole;
use App\Models\AgentConnection;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Client;
use Livewire\Livewire;
use Tests\Support\Scenario;

const CALLBACK = 'http://localhost:3999/callback';

beforeEach(function () {
    $this->s = Scenario::make();
    $this->agent = $this->s->party(ContractRole::Editor, required: true, agent: true)->party;
});

/**
 * Registers a client the way an MCP client does (dynamic client registration).
 */
function registerClient(object $t): string
{
    return $t->postJson('/oauth/register', ['client_name' => 'omp', 'redirect_uris' => [CALLBACK]])->assertCreated()->json('client_id');
}

/**
 * Runs authorize → consent → approve as $user. Returns [response of approve, code verifier].
 *
 * @param  array<string, mixed>  $choice
 * @return array{0: TestResponse, 1: string}
 */
function approveAs(object $t, User $user, string $clientId, array $choice, ?string $resource = null): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    $t->actingAs($user)->get('/oauth/authorize?'.http_build_query(array_filter([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CALLBACK,
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 'xyz', 'scope' => 'mcp:use',
        'resource' => $resource,
    ])))->assertOk()->assertSee('Connect omp to Akord');

    $response = $t->post(route('agents.connect.approve'), ['auth_token' => session('authToken'), ...$choice]);

    return [$response, $verifier];
}

/**
 * The full flow: returns an access and refresh token for the client.
 *
 * @param  list<string>  $abilities
 * @return array{access_token: string, refresh_token: string, client_id: string}
 */
function connect(object $t, ?Party $party = null, array $abilities = ['comment', 'approve'], ?string $clientId = null, ?string $resource = null): array
{
    $clientId ??= registerClient($t);
    [$approved, $verifier] = approveAs($t, $t->s->owner, $clientId, ['party_id' => ($party ?? $t->agent)->id, 'abilities' => $abilities], $resource);
    parse_str((string) parse_url((string) $approved->assertRedirect()->headers->get('Location'), PHP_URL_QUERY), $query);

    app('auth')->forgetGuards();
    $tokens = $t->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'redirect_uri' => CALLBACK,
        'code_verifier' => $verifier, 'code' => $query['code'],
    ])->assertOk()->json();

    return ['access_token' => $tokens['access_token'], 'refresh_token' => $tokens['refresh_token'], 'client_id' => $clientId];
}

function bearer(object $t, string $token, string $method, string $uri, array $data = []): TestResponse
{
    app('auth')->forgetGuards();
    auth()->guard('web')->logout();

    return $t->withToken($token)->json($method, $uri, $data);
}

test('an unauthenticated MCP request points the client at OAuth discovery', function () {
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertJsonPath('resource', url('/mcp'))->assertJsonPath('scopes_supported', ['mcp:use']);
    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('registration_endpoint', url('/oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

test('only clients with loopback callbacks can register themselves', function () {
    $this->postJson('/oauth/register', ['client_name' => 'omp', 'redirect_uris' => ['http://127.0.0.1:3000/callback']])->assertCreated()
        ->assertJsonPath('token_endpoint_auth_method', 'none');
    $this->postJson('/oauth/register', ['client_name' => 'evil', 'redirect_uris' => ['https://evil.example/callback']])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

test('an owner connects a client as an agent party, which then acts as that party with the chosen abilities', function () {
    $tokens = connect($this, abilities: ['comment']);

    bearer($this, $tokens['access_token'], 'GET', '/api/v1/agent')->assertOk()
        ->assertJsonPath('data.party.id', $this->agent->id)
        ->assertJsonPath('data.abilities', ['read', 'comment']);
    bearer($this, $tokens['access_token'], 'GET', '/api/v1/contracts')->assertOk()
        ->assertJsonPath('data.0.id', $this->s->contract->id);

    $step = $this->s->step();
    bearer($this, $tokens['access_token'], 'POST', "/api/v1/contracts/{$this->s->contract->id}/steps/{$step->id}/approval", ['revision' => 1])
        ->assertForbidden();
    expect(AgentConnection::query()->sole()->only(['party_id', 'approved_by']))->toBe(['party_id' => $this->agent->id, 'approved_by' => $this->s->owner->id]);
});

test('the MCP server answers OAuth clients as their agent party', function () {
    $tokens = connect($this);

    app('auth')->forgetGuards();
    $init = $this->withToken($tokens['access_token'])->postJson('/mcp', [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'omp', 'version' => '1']],
    ])->assertOk();
    app('auth')->forgetGuards();
    $text = $this->withToken($tokens['access_token'])->withHeaders(['MCP-Protocol-Version' => $init->json('result.protocolVersion')])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_contracts', 'arguments' => []]])
        ->assertOk()->json('result.content.0.text');

    expect($text)->toContain('"id": '.$this->s->contract->id);
});

test('only owners approve, and only for their own organisation’s agent parties', function () {
    $clientId = registerClient($this);
    $otherOrgAgent = Party::factory()->agent()->create();

    [$memberTry] = approveAs($this, $this->s->alpha->user, $clientId, ['party_id' => $this->agent->id]);
    $memberTry->assertForbidden();
    [$humanParty] = approveAs($this, $this->s->owner, $clientId, ['party_id' => $this->s->alpha->party->id]);
    $humanParty->assertForbidden();
    [$foreign] = approveAs($this, $this->s->owner, $clientId, ['party_id' => $otherOrgAgent->id]);
    $foreign->assertForbidden();

    expect(AgentConnection::query()->count())->toBe(0);
});

test('disconnecting a client revokes its access and refresh tokens', function () {
    $tokens = connect($this);
    bearer($this, $tokens['access_token'], 'GET', '/api/v1/agent')->assertOk();

    app(RevokeAgentConnection::class)->handle($this->s->owner, $this->agent, AgentConnection::query()->sole());

    bearer($this, $tokens['access_token'], 'GET', '/api/v1/agent')->assertUnauthorized();
    app('auth')->forgetGuards();
    $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
});

test('a connection stops working when its approver is no longer an owner', function () {
    $tokens = connect($this);

    $this->s->organisation->users()->updateExistingPivot($this->s->owner->id, ['role' => 'former']);

    bearer($this, $tokens['access_token'], 'GET', '/api/v1/agent')->assertForbidden();
    $this->s->organisation->users()->updateExistingPivot($this->s->owner->id, ['role' => OrganisationRole::Owner->value]);
    bearer($this, $tokens['access_token'], 'GET', '/api/v1/agent')->assertOk();
});

test('refreshed tokens keep acting as the agent party', function () {
    $tokens = connect($this);

    app('auth')->forgetGuards();
    $refreshed = $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'client_id' => $tokens['client_id'], 'refresh_token' => $tokens['refresh_token']])
        ->assertOk()->json('access_token');

    bearer($this, $refreshed, 'GET', '/api/v1/agent')->assertOk()->assertJsonPath('data.party.id', $this->agent->id);
});

test('owners change a connected tool’s abilities from the agent page without signing in again', function () {
    $tokens = connect($this, abilities: ['comment']);
    $step = $this->s->step();
    $approve = fn () => bearer($this, $tokens['access_token'], 'POST', "/api/v1/contracts/{$this->s->contract->id}/steps/{$step->id}/approval", ['revision' => 1]);

    $approve()->assertForbidden();

    $this->actingAs($this->s->owner);
    Livewire::test('pages::organisations.agent', ['organisation' => $this->s->organisation, 'party' => $this->agent])
        ->call('toggleAbility', 'connection', AgentConnection::query()->sole()->id, 'approve');

    expect(AgentConnection::query()->sole()->abilities)->toBe(['read', 'comment', 'approve']);
    $approve()->assertSuccessful();
});

test('the fallback command pre-registers a public client with loopback callbacks', function () {
    $this->artisan('akord:oauth-client', ['name' => 'omp'])->assertSuccessful();

    $client = Client::query()->where('name', 'omp')->sole();
    expect($client->confidential())->toBeFalse()
        ->and($client->redirect_uris)->toBe(['http://localhost:3000/callback', 'http://127.0.0.1:3000/callback']);
});

/**
 * MCP initialize at $uri with a bearer token; returns the response.
 */
function mcpInitialize(object $t, string $token, string $uri): TestResponse
{
    app('auth')->forgetGuards();
    auth()->guard('web')->logout();

    return $t->withToken($token)->postJson($uri, [
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'omp', 'version' => '1']],
    ]);
}

test('each agent party has its own MCP URL, with its own OAuth discovery', function () {
    $url = url("/mcp/agents/{$this->agent->id}");

    $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", resource_metadata="'.url("/.well-known/oauth-protected-resource/mcp/agents/{$this->agent->id}").'"');

    $this->getJson("/.well-known/oauth-protected-resource/mcp/agents/{$this->agent->id}")->assertOk()
        ->assertJsonPath('resource', $url);
});

test('a sign-in for one party’s URL works there and is refused at another party’s URL', function () {
    $other = $this->s->party(ContractRole::Commenter, agent: true)->party;
    $a = connect($this);
    $b = connect($this, $other);

    mcpInitialize($this, $a['access_token'], "/mcp/agents/{$this->agent->id}")->assertOk();
    mcpInitialize($this, $b['access_token'], "/mcp/agents/{$other->id}")->assertOk();
    mcpInitialize($this, $a['access_token'], "/mcp/agents/{$other->id}")->assertForbidden();
    mcpInitialize($this, $b['access_token'], "/mcp/agents/{$this->agent->id}")->assertForbidden();

    // Connecting the second repo kept the first connection.
    expect(AgentConnection::query()->active()->pluck('party_id')->sort()->values()->all())->toBe([$this->agent->id, $other->id]);
});

test('a client reused for a second party’s URL is shown the approval screen again, and keeps its first connection', function () {
    $other = $this->s->party(ContractRole::Commenter, agent: true)->party;
    $clientId = registerClient($this);
    $first = connect($this, clientId: $clientId, resource: url("/mcp/agents/{$this->agent->id}"));
    // Passport would skip the screen (the client already has a valid token); the second party isn't connected yet, so it doesn't.
    $second = connect($this, $other, clientId: $clientId, resource: url("/mcp/agents/{$other->id}"));

    expect(AgentConnection::query()->active()->pluck('party_id')->sort()->values()->all())->toBe([$this->agent->id, $other->id]);

    $init = mcpInitialize($this, $second['access_token'], "/mcp/agents/{$other->id}")->assertOk();
    app('auth')->forgetGuards();
    $text = $this->withToken($first['access_token'])->withHeaders(['MCP-Protocol-Version' => $init->json('result.protocolVersion')])
        ->postJson("/mcp/agents/{$this->agent->id}", ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'list_contracts', 'arguments' => []]])
        ->assertOk()->json('result.content.0.text');
    expect($text)->toContain('"id": '.$this->s->contract->id);
});

test('claim-code tokens work only at their own party’s URL', function () {
    $other = $this->s->party(ContractRole::Commenter, agent: true)->party;
    $token = $this->agent->createToken('repo', ['*'])->plainTextToken;

    mcpInitialize($this, $token, "/mcp/agents/{$this->agent->id}")->assertOk();
    mcpInitialize($this, $token, "/mcp/agents/{$other->id}")->assertForbidden();
});

test('the approval screen is fixed to the party named by the MCP URL', function () {
    $other = $this->s->party(ContractRole::Commenter, agent: true)->party;
    $foreign = Party::factory()->agent()->create(['name' => 'Foreign agent']);
    $clientId = registerClient($this);

    $this->actingAs($this->s->owner);
    $this->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CALLBACK, 'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256', 'state' => 'xyz', 'scope' => 'mcp:use', 'resource' => url("/mcp/agents/{$other->id}"),
    ]))->assertOk()
        ->assertSee('data-test="locked-party"', false)
        ->assertSee($other->name)
        ->assertDontSee($this->agent->name);

    $this->get('/oauth/authorize?'.http_build_query([
        'response_type' => 'code', 'client_id' => $clientId, 'redirect_uri' => CALLBACK, 'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256', 'state' => 'xyz', 'scope' => 'mcp:use', 'resource' => url("/mcp/agents/{$foreign->id}"),
    ]))->assertOk()
        ->assertSee('not an owner of')
        ->assertDontSee('data-test="connect-form"', false);
});
