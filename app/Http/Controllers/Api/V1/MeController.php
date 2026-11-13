<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends AgentApiController
{
    /**
     * GET /api/v1/agent: which agent party the credential acts as, and what it may do.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $party = $actor->party;

        return $this->respond([
            'party' => ['id' => $party?->id, 'name' => $party?->name],
            'organisation' => ['id' => $party?->organisation->id, 'name' => $party?->organisation->name],
            'abilities' => $actor->abilities(),
        ]);
    }
}
