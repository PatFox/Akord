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

#[Name('create_checklist')]
#[Description('Create the working checklist for a contract from its plan: stages of items to tick off as the work is done. Give your own stages and items (an item can link to the contract step it comes from with step_id), or omit stages to copy the contract\'s phases and steps. One checklist per contract version; replacing one that has ticked items needs replace: true. Needs an editor party. Use only when asked.')]
class CreateChecklist extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|string', ...ChecklistInput::rules()]);

            return $api->createChecklist($actor, $v['contract_id'], $v['stages'] ?? null, (bool) ($v['replace'] ?? false));
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->string()->required(),
            'stages' => $schema->array()->max(100)->items($schema->object([
                'title' => $schema->string()->max(200)->required(),
                'items' => $schema->array()->items($schema->object([
                    'title' => $schema->string()->max(300)->required(),
                    'notes' => $schema->string()->max(5000)->description('Optional detail (Markdown).'),
                    'step_id' => $schema->string()->description('The contract step this item comes from (get_contract gives step ids).'),
                ]))->required(),
            ]))->description('The checklist\'s stages in order. Omit to copy the contract\'s phases and steps.'),
            'replace' => $schema->boolean()->description('Replace an existing checklist even if items are ticked.'),
        ];
    }
}
