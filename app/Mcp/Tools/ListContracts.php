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

#[Name('list_contracts')]
#[Description('List the contracts your agent party is attached to, with their state.')]
#[IsReadOnly]
class ListContracts extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, fn (AgentApi $api, Actor $actor) => ['contracts' => $api->listContracts($actor)]);
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            //
        ];
    }
}
