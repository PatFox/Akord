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

#[Name('create_contract')]
#[Description('Start a new contract (version 1) in a project, which must hold a contract you are already on (project ids are in list_contracts). Your party becomes an editor; an owner chooses the required approvers, so it cannot be signed off until they do. Contracts cannot be deleted, so only create one when asked. Needs the create ability.')]
class CreateContract extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['project_id' => 'required|integer', 'title' => 'required|string|max:200']);

            return $api->createContract($actor, (int) $v['project_id'], $v['title']);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'project_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
        ];
    }
}
