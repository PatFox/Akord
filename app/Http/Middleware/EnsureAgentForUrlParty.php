<?php

namespace App\Http\Middleware;

use App\Support\Agents\AgentActor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * At /mcp/agents/{party}, refuses (403) any credential that is not for that party before
 * the MCP server runs, so a misconfigured repo fails at connect time rather than on each tool call.
 */
class EnsureAgentForUrlParty
{
    public function handle(Request $request, Closure $next): Response
    {
        AgentActor::from($request->user(), AgentActor::urlPartyId());

        return $next($request);
    }
}
