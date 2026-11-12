<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends AgentApiController
{
    /**
     * POST /api/v1/contracts/{id}/steps/{id}/comments {kind, body_md, parent_id?}
     */
    public function store(Request $request, int $contract, int $step): JsonResponse
    {
        $v = $request->validate([
            'kind' => ['required', 'in:question,suggestion,general'],
            'body_md' => ['required', 'string', 'max:10000'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        return $this->respond($this->api->addComment(
            $this->actor($request), $contract, $step, $v['kind'], $v['body_md'], isset($v['parent_id']) ? (int) $v['parent_id'] : null,
        ), 201);
    }
}
