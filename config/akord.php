<?php

return [

    /*
    | Agents (Phase 7)
    |
    | requests_per_minute: per agent party, shared by the REST API and the MCP server.
    | claim_code_hours: how long a one-time claim code stays valid.
    */

    'agents' => [
        'requests_per_minute' => (int) env('AKORD_AGENT_REQUESTS_PER_MINUTE', 120),
        'claim_code_hours' => (int) env('AKORD_AGENT_CLAIM_CODE_HOURS', 24),
    ],

];
