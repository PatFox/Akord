<?php

namespace App\Support;

use App\Enums\ContractRole;
use App\Models\Contract;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Which party a person acts for on a contract. People can belong to several parties;
 * the choice is remembered per contract in the session and re-validated on every use.
 */
final class ActingParties
{
    /**
     * Parties the user may act for: their parties attached to the contract (best role
     * first), then, for organisation owners, their other parties in the organisation.
     *
     * @return Collection<int, array{party: Party, role: ContractRole|null}>
     */
    public static function options(User $user, Contract $contract): Collection
    {
        $organisationId = $contract->organisationId();
        $isOwner = $user->isOwnerOf($organisationId);

        return $user->parties()
            ->orderBy('name')
            ->get()
            ->map(fn (Party $party) => ['party' => $party, 'role' => ContractAccess::roleOf($party, $contract)])
            ->filter(fn (array $option) => $option['role'] !== null || ($isOwner && $option['party']->organisation_id === $organisationId))
            ->sortByDesc(fn (array $option) => $option['role']?->rank() ?? 0)
            ->values();
    }

    public static function current(User $user, Contract $contract): ?Party
    {
        $options = self::options($user, $contract);
        $chosen = session(self::key($contract));

        return $options->first(fn (array $option) => $option['party']->id === $chosen)['party']
            ?? $options->first()['party']
            ?? null;
    }

    /**
     * @return bool false when the user cannot act for that party here
     */
    public static function choose(User $user, Contract $contract, int $partyId): bool
    {
        if (! self::options($user, $contract)->contains(fn (array $option) => $option['party']->id === $partyId)) {
            return false;
        }

        session([self::key($contract) => $partyId]);

        return true;
    }

    public static function actor(User $user, Contract $contract): Actor
    {
        return Actor::user($user, self::current($user, $contract));
    }

    private static function key(Contract $contract): string
    {
        return 'acting_party.'.$contract->getKey();
    }
}
