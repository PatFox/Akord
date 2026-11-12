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
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_step_history')]
#[Description('Get every revision of a step. Optionally include a unified diff between two revision numbers (diff_from, diff_to).')]
#[IsReadOnly]
class GetStepHistory extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate(['contract_id' => 'required|integer', 'step_id' => 'required|integer', 'diff_from' => 'nullable|integer|min:1', 'diff_to' => 'nullable|integer|min:1']);
            $history = $api->stepHistory($actor, (int) $v['contract_id'], (int) $v['step_id']);

            if (isset($v['diff_from'], $v['diff_to'])) {
                $history['diff'] = $api->diff($actor, (int) $v['contract_id'], (int) $v['step_id'], (int) $v['diff_from'], (int) $v['diff_to']);
            }

            return $history;
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
            'diff_from' => $schema->integer()->description('Revision number to diff from.'),
            'diff_to' => $schema->integer()->description('Revision number to diff to.'),
        ];
    }
}
