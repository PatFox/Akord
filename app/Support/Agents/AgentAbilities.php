<?php

namespace App\Support\Agents;

/**
 * Token abilities an owner can grant an agent. 'read' is always granted.
 */
final class AgentAbilities
{
    public const ALL = ['read', 'comment', 'edit', 'approve'];

    /**
     * @param  array<int, mixed>  $requested
     * @return list<string>
     */
    public static function normalise(array $requested): array
    {
        return array_values(array_intersect(self::ALL, ['read', ...array_map(strval(...), $requested)]));
    }
}
