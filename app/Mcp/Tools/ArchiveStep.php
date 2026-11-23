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

#[Name('archive_step')]
#[Description('Propose archiving a step. It saves a new revision with the same text that proposes the archive (archive_proposed: true); the step stays in the contract, and its earlier approvals stop counting, until every required party approves that revision with approve_step, and then it is archived and leaves the contract. Any revise_step on it, even with the same text, withdraws the proposal. Without required approvers it is archived at once. Archived steps cannot be restored; only do it when the user asks.')]
class ArchiveStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|string', 'step_id' => 'required|string']);

            return $api->archiveStep($actor, $v['contract_id'], $v['step_id']);
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
        ];
    }
}
