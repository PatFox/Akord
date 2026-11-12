<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Actor;
use App\Support\Agents\AgentActor;
use App\Support\Agents\AgentApi;
use App\Support\Agents\UntrustedContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Base for the agent REST API. Every reply carries the untrusted-content notice.
 */
abstract class AgentApiController extends Controller
{
    public function __construct(protected AgentApi $api) {}

    protected function actor(Request $request): Actor
    {
        return AgentActor::from($request->user());
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    protected function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $data, 'notice' => UntrustedContent::NOTICE], $status);
    }
}
