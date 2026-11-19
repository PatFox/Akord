<?php

namespace App\Mcp\Tools;

use App\Actions\Checklists\ChecklistInput;
use App\Support\Actor;
use App\Support\Agents\AgentApi;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('add_checklist_item')]
#[Description('Add an item at the end of a checklist stage, for work found along the way (stage ids are in get_checklist). Needs an editor party.')]
class AddChecklistItem extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|string', 'stage_id' => 'required|string', ...ChecklistInput::itemRules()]);

            return $api->addChecklistItem($actor, $v['contract_id'], $v['stage_id'], $v['title'], $v['notes'] ?? null, $v['step_id'] ?? null);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->string()->required(),
            'stage_id' => $schema->string()->required(),
            'title' => $schema->string()->max(300)->required(),
            'notes' => $schema->string()->max(5000)->description('Optional detail (Markdown).'),
            'step_id' => $schema->string()->description('The contract step this item comes from.'),
        ];
    }
}
