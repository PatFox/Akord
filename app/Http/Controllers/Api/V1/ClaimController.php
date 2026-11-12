<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Agents\ClaimAgentToken;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClaimController extends Controller
{
    /**
     * POST /api/v1/agent/claim {code, name?}: exchange a one-time claim code for a token.
     */
    public function __invoke(Request $request, ClaimAgentToken $claim): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $result = $claim->handle($validated['code'], $validated['name'] ?? 'agent');
        $party = $result['party'];

        return response()->json(['data' => [
            'token' => $result['token'],
            'token_type' => 'Bearer',
            'abilities' => $result['abilities'],
            'party' => ['id' => $party->id, 'name' => $party->name],
            'organisation' => ['id' => $party->organisation->id, 'name' => $party->organisation->name],
            'api_base_url' => url('/api/v1'),
            'mcp_url' => url('/mcp'),
        ]], 201);
    }
}
