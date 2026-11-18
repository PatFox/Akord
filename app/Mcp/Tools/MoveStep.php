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
#[Description('Move a step within its phase or to another phase of the same contract. Without phase_id it stays in its phase; without after_step_id it goes to the end, and "start" puts it first. Approvals and sign-offs are not affected.')]
class MoveStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|string', 'step_id' => 'required|string',
                'phase_id' => 'nullable|string', 'after_step_id' => 'nullable|string',
            ]);

            return $api->moveStep(
                $actor, $v['contract_id'], $v['step_id'],
                $v['phase_id'] ?? null,
                $v['after_step_id'] ?? null,
            );
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
            'phase_id' => $schema->string()->description('The phase to move it to; omit to keep its phase.'),
            'after_step_id' => $schema->string()->description('Place it directly after this step of the target phase; "start" for first; omit for last.'),
        ];
    }
}
