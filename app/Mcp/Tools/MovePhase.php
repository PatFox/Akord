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

#[Name('move_phase')]
#[Description('Reorder a contract\'s phases: move one phase, with its steps, to another place (phase ids are in get_contract). Without after_phase_id it goes to the end, and "start" puts it first. Approvals and sign-offs are not affected.')]
class MovePhase extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|string', 'phase_id' => 'required|string',
                'after_phase_id' => 'nullable|string',
            ]);

            return $api->movePhase($actor, $v['contract_id'], $v['phase_id'], $v['after_phase_id'] ?? null);
        });
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'contract_id' => $schema->string()->required(),
            'phase_id' => $schema->string()->required(),
            'after_phase_id' => $schema->string()->description('Place it directly after this phase; "start" for first; omit for last.'),
        ];
    }
}
