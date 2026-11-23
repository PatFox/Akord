<?php

namespace App\Support\Agents;

use App\Models\Party;
use App\Support\RandomId;

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
     * The MCP configuration that points the `akord` server at the party's URL (the `mcpServers`
     * shape omp, Claude Code and most clients read). It holds no secret: the client signs in with OAuth.
     *
     * @return array{mcpServers: array{akord: array{type: string, url: string}}}
     */
    public static function config(Party $party): array
    {
        return ['mcpServers' => ['akord' => ['type' => 'http', 'url' => self::for($party)]]];
    }

    /**
     * The party id in an OAuth `resource` (or any URL) of the form …/mcp/agents/{id}; null otherwise.
     */
    public static function partyIdFrom(mixed $url): ?string
    {
        if (! is_string($url) || $url === '') {
            return null;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#^/mcp/agents/([^/]+)/?$#', $path, $match) === 1 && RandomId::isValid($match[1]) ? $match[1] : null;
    }
}
