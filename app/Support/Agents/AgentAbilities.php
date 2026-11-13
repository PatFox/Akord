<?php

namespace App\Support\Agents;

/**
 * Token abilities an owner can grant an agent. 'read' is always granted.
 */
final class AgentAbilities
{
    public const ALL = ['read', 'comment', 'edit', 'approve', 'create'];

    /**
     * @param  array<int, mixed>  $requested
     * @return list<string>
     */
    public static function normalise(array $requested): array
    {
        return array_values(array_intersect(self::ALL, ['read', ...array_map(strval(...), $requested)]));
    }

    /**
     * The optional abilities with their labels, for every screen that grants them.
     *
     * @return array<string, string>
     */
    public static function optionalLabels(): array
    {
        return [
            'comment' => (string) __('Comment'),
            'edit' => (string) __('Edit steps'),
            'approve' => (string) __('Approve and sign off'),
            'create' => (string) __('Create contracts'),
        ];
    }
}
