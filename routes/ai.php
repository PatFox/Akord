<?php

use App\Mcp\Servers\ContractServer;
use Laravel\Mcp\Facades\Mcp;

// MCP server for agents (Phase 7): same bearer tokens and per-agent rate limit as the REST API.
Mcp::web('/mcp', ContractServer::class)->middleware(['auth:sanctum', 'throttle:agent']);
