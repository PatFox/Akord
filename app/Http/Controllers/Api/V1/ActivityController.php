<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends AgentApiController
{
    /**
     * GET /api/v1/activity?since=<cursor>&limit=<n>: events after the cursor, in commit
     * order. Pass next_cursor back as since to continue.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $v = $request->validate(['since' => ['nullable', 'integer', 'min:0'], 'limit' => ['nullable', 'integer', 'min:1', 'max:500']]);

        return $this->respond($this->api->activitySince($this->actor($request), (int) ($v['since'] ?? 0), (int) ($v['limit'] ?? 100)));
    }
}
