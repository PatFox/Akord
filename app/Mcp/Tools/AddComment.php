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

#[Name('add_comment')]
#[Description('Comment on a step\'s current revision (a question, a suggestion, or a general remark), or reply to a comment with parent_id.')]
class AddComment extends AgentTool
{
    public function handle(Request $request): Response
    {
        return $this->run($request, function (AgentApi $api, Actor $actor) use ($request) {
            $v = $request->validate([
                'contract_id' => 'required|integer', 'step_id' => 'required|integer',
                'kind' => 'required|in:question,suggestion,general', 'body_md' => 'required|string|max:10000', 'parent_id' => 'nullable|integer',
            ]);

            return $api->addComment($actor, (int) $v['contract_id'], (int) $v['step_id'], $v['kind'], $v['body_md'], isset($v['parent_id']) ? (int) $v['parent_id'] : null);
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
            'kind' => $schema->string()->enum(['question', 'suggestion', 'general'])->required(),
            'body_md' => $schema->string()->description('The comment (Markdown).')->required(),
            'parent_id' => $schema->integer()->description('Reply to this comment.'),
        ];
    }
}
