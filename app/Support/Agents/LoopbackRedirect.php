<?php

namespace App\Support\Agents;

use Laravel\Passport\Client;

/**
 * Local MCP clients (omp, Claude Code) receive the OAuth callback on a loopback port they pick each time, and may
 * reuse a client they registered earlier from another port. RFC 8252 §7.3 asks the authorization server to accept any
 * port for loopback redirects. league/oauth2-server does so for 127.0.0.1 and [::1] but compares "localhost"
 * exactly, which is what omp registers; so the authorize endpoint records the new port on the client first.
 *
 * Only for public clients whose every registered redirect is plain-http loopback, and only for a redirect with the
 * same path as one of them: a client can move between local ports, never to another host.
 */
final class LoopbackRedirect
{
    private const HOSTS = ['localhost', '127.0.0.1', '[::1]'];

    /** The authorize endpoint runs this before login, so the list is capped: the first URI plus the latest others. */
    public const MAX_URIS = 10;

    /**
     * Adds $redirectUri to the client's redirect URIs if it is the same loopback callback on another port.
     */
    public static function allowPortChange(Client $client, string $redirectUri): void
    {
        $registered = array_values(array_filter((array) $client->getAttribute('redirect_uris'), is_string(...)));
        if ($client->confidential() || $registered === [] || in_array($redirectUri, $registered, true)) {
            return;
        }

        $requested = self::loopback($redirectUri);
        if ($requested === null) {
            return;
        }

        $paths = [];
        foreach ($registered as $uri) {
            $parts = self::loopback($uri);
            if ($parts === null) {
                return;
            }
            $paths[] = $parts['path'];
        }

        if (in_array($requested['path'], $paths, true)) {
            $others = array_slice([...array_slice($registered, 1), $redirectUri], -(self::MAX_URIS - 1));
            $client->forceFill(['redirect_uris' => [$registered[0], ...$others]])->save();
        }
    }

    /**
     * @return array{host: string, path: string}|null the parts of a plain-http loopback URI with a port, else null
     */
    private static function loopback(string $uri): ?array
    {
        $parts = parse_url($uri);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'http' || ! isset($parts['port'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $host = strtolower($parts['host']);

        return in_array($host, self::HOSTS, true) ? ['host' => $host, 'path' => $parts['path'] ?? '/'] : null;
    }
}
