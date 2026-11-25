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

#[Name('move_checklist_item')]
#[Description('Reorder a checklist item within its stage, or move it to another stage of the same checklist (ids are in get_checklists). Without stage_id it stays in its stage; without after_item_id it goes to the end, and "start" puts it first. The tick stays. Needs an editor party; 423 if the contract is complete or cancelled.')]
class MoveChecklistItem extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|string', 'item_id' => 'required|string',
                'stage_id' => 'nullable|string', 'after_item_id' => 'nullable|string',
            ]);

            return $api->moveChecklistItem($actor, $v['contract_id'], $v['item_id'], $v['stage_id'] ?? null, $v['after_item_id'] ?? null);
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
            'stage_id' => $schema->string()->description('A stage of the same checklist to move it to; omit to keep its stage.'),
            'after_item_id' => $schema->string()->description('Place it directly after this item of the target stage; "start" for first; omit for last.'),
        ];
    }
}
