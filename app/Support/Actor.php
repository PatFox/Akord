<?php

namespace App\Support;

use App\Models\Party;
use App\Models\User;
use InvalidArgumentException;

/**
 * Who performs an action: a person acting for one of their parties (the party is
 * optional for owner-only administration), or an agent party with its token abilities.
 */
final class Actor
{
    /**
     * @param  list<string>  $abilities
     */
    private function __construct(
        public readonly ?User $user,
        public readonly ?Party $party,
        private readonly array $abilities,
    ) {}

    public static function user(User $user, ?Party $party = null): self
    {
        if ($party?->isAgent()) {
            throw new InvalidArgumentException('People cannot act as an agent party.');
        }

        return new self($user, $party, ['*']);
    }

    /**
     * @param  list<string>  $abilities  the agent's token abilities
     */
    public static function agent(Party $agent, array $abilities = ['*']): self
    {
        if (! $agent->isAgent()) {
            throw new InvalidArgumentException('Agent actors must be agent parties.');
        }

        return new self(null, $agent, $abilities);
    }

    public function isAgent(): bool
    {
        return $this->user === null;
    }

    public function tokenAllows(string $ability): bool
    {
        return in_array('*', $this->abilities, true) || in_array($ability, $this->abilities, true);
    }

    /**
     * @return list<string>
     */
    public function abilities(): array
    {
        return $this->abilities;
    }

    /**
     * The model visibility queries are scoped to.
     */
    public function principal(): User|Party
    {
        return $this->user ?? $this->party ?? throw new InvalidArgumentException('An actor needs a user or a party.');
    }
}
