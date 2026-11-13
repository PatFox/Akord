<?php

use App\Http\Middleware\EnsureAgentForUrlParty;
use App\Mcp\Servers\ContractServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth for MCP clients: discovery documents and dynamic client registration
// (/.well-known/oauth-*, POST /oauth/register). Passport issues the tokens; the
// consent screen (resources/views/oauth/authorize.blade.php) picks the agent party.
Mcp::oauthRoutes();

// MCP server for agents: accepts claim-code tokens (Sanctum) and OAuth tokens (Passport),
// with the same per-agent rate limit as the REST API.
Mcp::web('/mcp', ContractServer::class)->middleware(['auth:sanctum,api', 'throttle:agent']);

// The same server at one URL per agent party. Each repo's MCP config names its party's URL;
// clients keep one sign-in per URL, and a credential only works at its own party's URL
// (App\Support\Agents\AgentActor).
Mcp::web('/mcp/agents/{party}', ContractServer::class)
    ->where('party', '[0-9]+')
    ->middleware(['auth:sanctum,api', 'throttle:agent', EnsureAgentForUrlParty::class]);
