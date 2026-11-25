<?php

namespace App\Mcp\Tools;

use App\Actions\Checklists\ChecklistInput;
use App\Actions\Checklists\UpdateChecklistItem as UpdateItem;
use App\Support\Actor;
use App\Support\Agents\AgentApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('update_checklist_item')]
#[Description('Change a checklist item\'s title, notes or linked step (item ids are in get_checklists). Only the fields you pass change; an empty notes or step_id clears it. The tick stays. Needs an editor party; 423 if the contract is complete or cancelled.')]
class UpdateChecklistItem extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $rules = ChecklistInput::itemRules();
            $rules['title'] = ['sometimes', ...$rules['title']];
            $v = $request->validate(['contract_id' => 'required|string', 'item_id' => 'required|string', ...$rules]);

            return $api->updateChecklistItem($actor, $v['contract_id'], $v['item_id'], array_intersect_key($v, array_flip(UpdateItem::FIELDS)));
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
            'title' => $schema->string()->max(300)->description('The new title; omit to keep it.'),
            'notes' => $schema->string()->max(5000)->description('New detail (Markdown); "" clears it; omit to keep it.'),
            'step_id' => $schema->string()->description('The contract step this item comes from; "" clears the link; omit to keep it.'),
        ];
    }
}
