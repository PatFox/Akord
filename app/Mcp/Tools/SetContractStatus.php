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

#[Name('set_contract_status')]
#[Description('Mark a contract open, complete or cancelled. Complete and cancelled contracts are read-only until reopened (status open). Needs an editor party; only the latest version can change. Use only when asked.')]
class SetContractStatus extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|string', 'status' => ['required', 'string', Rule::enum(ContractStatus::class)]]);

            return $api->setStatus($actor, $v['contract_id'], ContractStatus::from($v['status']));
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->string()->required(),
            'status' => $schema->string()->enum(ContractStatus::class)->required(),
        ];
    }
}
