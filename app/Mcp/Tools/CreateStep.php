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
#[Description('Add a step to a phase (phase ids are in get_contract). Without after_step_id it goes at the end of the phase; "start" puts it first. It starts at revision 1 with no approvals, makes the contract not ready for sign-off, and cancels any sign-offs.')]
class CreateStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|string', 'phase_id' => 'required|string',
                'title' => 'required|string|max:200', 'description_md' => 'present|nullable|string|max:20000',
                'change_note' => 'nullable|string|max:500', 'after_step_id' => 'nullable|string',
            ]);

            return $api->createStep(
                $actor, $v['contract_id'], $v['phase_id'], $v['title'], $v['description_md'] ?? '', $v['change_note'] ?? null,
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
            'phase_id' => $schema->string()->required(),
            'title' => $schema->string()->description('The step title, without a number ("Error format", not "1.2 Error format"): Akord numbers steps itself.')->required(),
            'description_md' => $schema->string()->description('The description (Markdown).')->required(),
            'change_note' => $schema->string()->description('Why you added it.'),
            'after_step_id' => $schema->string()->description('Place it directly after this step of the phase; "start" for first; omit for last.'),
        ];
    }
}
