<?php

namespace Tests\Support;

use App\Enums\ContractRole;
use App\Models\Party;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Calls the agent REST API and MCP server over HTTP with a real bearer token.
 * Auth guards are reset before each call: Laravel caches the resolved user across
 * requests in one test, which would hide revoked tokens.
 */
final class AgentClient
{
    public function __construct(private TestCase $test, public Party $party, public string $token) {}

    /**
     * An agent party attached to the scenario's contract, with a real token.
     *
     * @param  list<string>  $abilities
     */
    public static function on(TestCase $test, Scenario $s, ContractRole $role = ContractRole::Editor, bool $required = true, array $abilities = ['read', 'comment', 'edit', 'approve']): self
    {
        $party = $s->party($role, required: $required, agent: true)->party;

        return new self($test, $party, $party->createToken('test', $abilities)->plainTextToken);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function api(string $method, string $uri, array $data = []): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->test->withToken($this->token)->json($method, '/api/v1/'.ltrim($uri, '/'), $data);
    }

    /**
     * Calls an MCP tool and returns [isError, text].
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: bool, 1: string}
     */
    public function tool(string $name, array $arguments = []): array
    {
        $result = $this->mcp('tools/call', ['name' => $name, 'arguments' => $arguments])->json('result');

        return [(bool) ($result['isError'] ?? false), (string) ($result['content'][0]['text'] ?? '')];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function mcp(string $method, array $params = []): TestResponse
    {
        app('auth')->forgetGuards();
        $init = $this->test->withToken($this->token)->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ]);
        $version = (string) $init->json('result.protocolVersion');

        app('auth')->forgetGuards();

        return $this->test->withToken($this->token)
            ->withHeaders(['MCP-Protocol-Version' => $version, 'Mcp-Session-Id' => (string) $init->headers->get('Mcp-Session-Id')])
            ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => $method, 'params' => $params]);
    }
}
