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

#[Name('create_step')]
#[Description('Add a step to a phase (phase ids are in get_contract). Without after_step_id it goes at the end of the phase; 0 puts it first. It starts at revision 1 with no approvals, makes the contract not ready for sign-off, and cancels any sign-offs.')]
class CreateStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|integer', 'phase_id' => 'required|integer',
                'title' => 'required|string|max:200', 'description_md' => 'present|nullable|string|max:20000',
                'change_note' => 'nullable|string|max:500', 'after_step_id' => 'nullable|integer|min:0',
            ]);

            return $api->createStep(
                $actor, (int) $v['contract_id'], (int) $v['phase_id'], $v['title'], $v['description_md'] ?? '', $v['change_note'] ?? null,
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
            'phase_id' => $schema->integer()->required(),
            'title' => $schema->string()->required(),
            'description_md' => $schema->string()->description('The description (Markdown).')->required(),
            'change_note' => $schema->string()->description('Why you added it.'),
            'after_step_id' => $schema->integer()->min(0)->description('Place it directly after this step of the phase; 0 for first; omit for last.'),
        ];
    }
}
