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

#[Name('move_step')]
#[Description('Move a step within its phase or to another phase of the same contract. Without phase_id it stays in its phase; without after_step_id it goes to the end, and 0 puts it first. Approvals and sign-offs are not affected.')]
class MoveStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|integer', 'step_id' => 'required|integer',
                'phase_id' => 'nullable|integer', 'after_step_id' => 'nullable|integer|min:0',
            ]);

            return $api->moveStep(
                $actor, (int) $v['contract_id'], (int) $v['step_id'],
                isset($v['phase_id']) ? (int) $v['phase_id'] : null,
                isset($v['after_step_id']) ? (int) $v['after_step_id'] : null,
            );
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->integer()->required(),
            'step_id' => $schema->integer()->required(),
            'phase_id' => $schema->integer()->description('The phase to move it to; omit to keep its phase.'),
            'after_step_id' => $schema->integer()->min(0)->description('Place it directly after this step of the target phase; 0 for first; omit for last.'),
        ];
    }
}
