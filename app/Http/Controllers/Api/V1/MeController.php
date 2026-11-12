<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class MeController extends AgentApiController
{
    /**
     * GET /api/v1/agent: who the token belongs to and what it may do.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $party = $this->actor($request)->party;
        $token = $party?->currentAccessToken();

        return $this->respond([
            'party' => ['id' => $party?->id, 'name' => $party?->name],
            'organisation' => ['id' => $party?->organisation->id, 'name' => $party?->organisation->name],
            'abilities' => $token instanceof PersonalAccessToken ? $token->abilities : [],
        ]);
    }
}
