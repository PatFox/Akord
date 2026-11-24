<?php

namespace App\Http\Controllers\Agents;

use App\Actions\Agents\UseAgentConnectionLink;
use App\Exceptions\ClaimCodeUnavailableException;
use App\Http\Controllers\Controller;
use App\Models\AgentConnectionLink;
use App\Models\Party;
use App\Support\Agents\PartyMcpUrl;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * The public end of a connection link (CreateAgentConnectionLink), read by the agent it was given to:
 *
 * - GET /connect/{token}: Markdown instructions; does not use up the link, so link previews are harmless.
 * - POST /connect/{token}: uses up the link and returns the party's MCP configuration (no secrets).
 *
 * Errors are plain text or JSON rather than HTML pages, because an agent reads them.
 */
class ConnectionLinkController extends Controller
{
    private const UNKNOWN = 'Unknown connection link. Check the link, or ask an owner for a new one on the agent party’s Agent access page in Akord.';

    public function show(string $token): Response
    {
        $link = AgentConnectionLink::query()->with('party.organisation')->where('token_hash', AgentConnectionLink::hashToken($token))->first();

        return match (true) {
            $link === null => self::markdown("# Unknown connection link\n\n".self::UNKNOWN."\n", 404),
            ! $link->isUsable() => self::markdown("# This connection link can no longer be used\n\n{$link->unusableReason()}\n", 410),
            default => self::markdown(self::instructions($link->party, route('agents.link.claim', $token))),
        };
    }

    public function claim(string $token, UseAgentConnectionLink $use): JsonResponse
    {
        try {
            $party = $use->handle($token);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => self::UNKNOWN], 404);
        } catch (ClaimCodeUnavailableException $e) {
            return response()->json(['message' => $e->getMessage()], 410);
        }

        return response()->json(PartyMcpUrl::config($party), 200, [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private static function markdown(string $text, int $status = 200): Response
    {
        return response($text, $status, ['Content-Type' => 'text/markdown; charset=UTF-8']);
    }

    private static function instructions(Party $party, string $claimUrl): string
    {
        $organisation = $party->organisation->name;

        return <<<MD
        # Connect this repository to Akord

        This link connects the repository you are working in to Akord as the agent party **{$party->name}** ({$organisation}).
        Do steps 1 to 3 yourself; the user should not have to copy anything.

        1. Fetch the MCP configuration by sending an empty POST to this link. It works only once, so send it once:

           ```
           curl -sS -X POST {$claimUrl}
           ```

           It returns JSON of the form `{"mcpServers": {"akord": {"type": "http", "url": "…"}}}`. It holds no secret.
           If it returns `{"message": …}` instead, show that message to the user and stop.

        2. Save the `akord` server for your MCP client:
           - **omp:** merge it into `.omp/mcp.json` at the repository root, creating the file if needed and keeping any other servers in it.
             Do not use `.omp/.mcp.json`: omp reads it, but `/mcp list` and `/mcp reauth akord` do not see a server from it until it has connected, and it cannot connect before the sign-in.
           - **Claude Code:** run `claude mcp add --transport http akord <url>` with the URL from the JSON. It is stored outside the repository, so skip step 3.
           - **Another client:** add the URL as an HTTP MCP server named `akord` in its project configuration.

        3. Keep the file out of git: unless `git check-ignore -q .omp/mcp.json` succeeds, add the line `/.omp/mcp.json` to the repository's `.gitignore`.

        4. Tell the user to finish the sign-in, which only they can do: in omp, run `/mcp reload`, then `/mcp reauth akord`
           (in Claude Code, `/mcp` and authenticate `akord`), and approve in the browser while signed in to Akord as an owner of {$organisation}.
           What you may do in Akord is set there, not during sign-in.

        MD;
    }
}
