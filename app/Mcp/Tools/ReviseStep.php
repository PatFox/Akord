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

#[Name('revise_step')]
#[Description('Save a new revision of a step\'s title and Markdown description. base_revision must be the revision you edited; if someone saved since, you get a 409 and must re-read the step. Content changes cancel approvals.')]
class ReviseStep extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|integer', 'step_id' => 'required|integer', 'base_revision' => 'required|integer|min:1',
                'title' => 'required|string|max:200', 'description_md' => 'present|nullable|string|max:20000', 'change_note' => 'nullable|string|max:500',
            ]);

            return $api->reviseStep($actor, (int) $v['contract_id'], (int) $v['step_id'], (int) $v['base_revision'], $v['title'], $v['description_md'] ?? '', $v['change_note'] ?? null);
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
            'base_revision' => $schema->integer()->min(1)->description('The revision number you started from.')->required(),
            'title' => $schema->string()->required(),
            'description_md' => $schema->string()->description('The full new description (Markdown).')->required(),
            'change_note' => $schema->string()->description('Why you changed it.'),
        ];
    }
}
