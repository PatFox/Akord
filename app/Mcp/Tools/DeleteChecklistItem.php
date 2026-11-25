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

#[Name('delete_checklist_item')]
#[Description('Delete a checklist item, ticked or not (item ids are in get_checklists). It cannot be undone: use it for items that are wrong, duplicated or no longer needed, not to hide unfinished work. Needs an editor party; 423 if the contract is complete or cancelled.')]
class DeleteChecklistItem extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|string', 'item_id' => 'required|string']);

            return $api->deleteChecklistItem($actor, $v['contract_id'], $v['item_id']);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->string()->required(),
            'item_id' => $schema->string()->required(),
        ];
    }
}
