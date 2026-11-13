<?php

use App\Mcp\Servers\ContractServer;
use Laravel\Mcp\Facades\Mcp;

// OAuth for MCP clients: discovery documents and dynamic client registration
// (/.well-known/oauth-*, POST /oauth/register). Passport issues the tokens; the
// consent screen (resources/views/oauth/authorize.blade.php) picks the agent party.
Mcp::oauthRoutes();

// MCP server for agents: accepts claim-code tokens (Sanctum) and OAuth tokens (Passport),
// with the same per-agent rate limit as the REST API.
Mcp::web('/mcp', ContractServer::class)->middleware(['auth:sanctum,api', 'throttle:agent']);
