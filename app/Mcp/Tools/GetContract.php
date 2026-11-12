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

#[Name('get_contract')]
#[Description('Get a contract: phases, steps (current revision), required parties, sign-offs and the fingerprint. Set format to markdown for a readable export.')]
#[IsReadOnly]
class GetContract extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|integer', 'format' => 'nullable|in:json,markdown']);

            return ($v['format'] ?? 'json') === 'markdown'
                ? ['content' => ['markdown' => $api->contractMarkdown($actor, (int) $v['contract_id'])]]
                : $api->contract($actor, (int) $v['contract_id']);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->description('The contract id.')->required(),
            'format' => $schema->string()->enum(['json', 'markdown'])->description('json (default) or markdown.'),
        ];
    }
}
