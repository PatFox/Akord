<?php

namespace App\Support\Agents;

use App\Models\Party;

/**
 * Each agent party's own MCP URL, /mcp/agents/{id}. A repo whose agent represents the party
 * points its MCP config here; MCP clients keep one sign-in per URL and send it to the
 * authorization server as the OAuth `resource`.
 */
final class PartyMcpUrl
{
    public static function for(Party $party): string
    {
        return url('/mcp/agents/'.$party->id);
    }

    /**
     * The party id in an OAuth `resource` (or any URL) of the form …/mcp/agents/{id}; null otherwise.
     */
    public static function partyIdFrom(mixed $url): ?int
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#^/mcp/agents/(\d+)/?$#', $path, $match) === 1 ? (int) $match[1] : null;
    }
}
