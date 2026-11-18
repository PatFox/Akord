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

#[Name('approve_step')]
#[Description('Approve a step for your party. Pass the revision number you reviewed; if the content changed since, you get a 409 and must review again.')]
class ApproveStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|string', 'step_id' => 'required|string', 'revision' => 'required|integer|min:1']);

            return $api->approveStep($actor, $v['contract_id'], $v['step_id'], (int) $v['revision']);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->string()->required(),
            'step_id' => $schema->string()->required(),
            'revision' => $schema->integer()->min(1)->description('The revision number you reviewed.')->required(),
        ];
    }
}
