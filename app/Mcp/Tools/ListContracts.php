<?php

namespace App\Mcp\Tools;

use App\Enums\ContractStatus;
use App\Support\Actor;
use App\Support\Agents\AgentApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list_contracts')]
#[Description('List the contracts your agent party is attached to, with their state and status. Only open contracts unless you pass status.')]
#[IsReadOnly]
class ListContracts extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['status' => 'nullable|array', 'status.*' => ['string', Rule::enum(ContractStatus::class)]]);

            return ['contracts' => $api->listContracts($actor, array_values(array_map(ContractStatus::from(...), $v['status'] ?? [])))];
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->array()->items($schema->string()->enum(ContractStatus::class))
                ->description('Which statuses to list (default ["open"]). Complete and cancelled contracts are read-only.'),
        ];
    }
}
