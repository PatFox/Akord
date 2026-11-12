<?php

namespace App\Mcp\Tools;

use App\Support\Actor;
use App\Support\Agents\AgentApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_activity_since')]
#[Description('Get activity on your contracts after a cursor, in commit order. Start with since=0, then pass back next_cursor.')]
#[IsReadOnly]
class GetActivitySince extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['since' => 'nullable|integer|min:0', 'limit' => 'nullable|integer|min:1|max:500']);

            return $api->activitySince($actor, (int) ($v['since'] ?? 0), (int) ($v['limit'] ?? 100));
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'since' => $schema->integer()->min(0)->description('Cursor: the next_cursor from your previous call, or 0.'),
            'limit' => $schema->integer()->min(1)->max(500)->description('Maximum events (default 100).'),
        ];
    }
}
