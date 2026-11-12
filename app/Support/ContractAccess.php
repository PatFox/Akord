<?php

namespace App\Support;

use App\Enums\ContractAbility;
use App\Enums\ContractRole;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Party;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * The single access rule (PLAN §3). Contracts the actor cannot see are reported as
 * missing (404) so their existence never leaks; visible but forbidden is 403.
 */
final class ContractAccess
{
    /**
     * @throws ModelNotFoundException<Contract>
     */
    public static function ensureVisible(Actor $actor, Contract $contract): void
    {
        $visible = Contract::query()->visibleTo($actor->principal())->whereKey($contract->getKey())->exists();

        if (! $visible) {
            throw (new ModelNotFoundException)->setModel(Contract::class, [$contract->getKey()]);
        }
    }

    /**
     * @throws ModelNotFoundException<Contract>
     * @throws AuthorizationException
     * @throws InvalidActionException
     */
    public static function authorize(Actor $actor, Contract $contract, ContractAbility $ability): void
    {
        self::ensureVisible($actor, $contract);

        if (! $actor->tokenAllows($ability->tokenAbility())) {
            throw new AuthorizationException("This token lacks the '{$ability->tokenAbility()}' ability.");
        }

        $organisationId = $contract->organisationId();
        $isOwner = $actor->user?->isOwnerOf($organisationId) ?? false;

        if ($ability === ContractAbility::View) {
            return;
        }

        if ($ability === ContractAbility::Administer) {
            if (! $isOwner) {
                throw new AuthorizationException('Only organisation owners can do this.');
            }

            return;
        }

        $party = self::actingParty($actor);
        $role = self::roleOf($party, $contract);
        $ownerAsOwnParty = $isOwner && $party->organisation_id === $organisationId;

        $allowed = match ($ability) {
            ContractAbility::Edit => $role === ContractRole::Editor || $ownerAsOwnParty,
            ContractAbility::Comment => in_array($role, [ContractRole::Editor, ContractRole::Commenter], true) || $ownerAsOwnParty,
            // Owners get no bypass: approvals belong to the parties on the contract (D2).
            ContractAbility::Approve => in_array($role, [ContractRole::Editor, ContractRole::Commenter], true),
        };

        if (! $allowed) {
            throw new AuthorizationException('Your party\'s role on this contract does not allow this.');
        }
    }

    /**
     * Whether authorize() would pass; for deciding which controls to show.
     */
    public static function allows(Actor $actor, Contract $contract, ContractAbility $ability): bool
    {
        try {
            self::authorize($actor, $contract, $ability);
        } catch (AuthorizationException|InvalidActionException|ModelNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * The party the actor acts for, after checking a person belongs to it.
     *
     * @throws AuthorizationException
     * @throws InvalidActionException
     */
    public static function actingParty(Actor $actor): Party
    {
        $party = $actor->party ?? throw new InvalidActionException('Choose the party you are acting for.');

        if ($actor->user !== null && ! $party->users()->whereKey($actor->user->getKey())->exists()) {
            throw new AuthorizationException('You are not a member of that party.');
        }

        return $party;
    }

    public static function roleOf(Party $party, Contract $contract): ?ContractRole
    {
        return self::membership($party, $contract)?->role;
    }

    public static function membership(Party $party, Contract $contract): ?ContractParty
    {
        return ContractParty::query()
            ->where('contract_id', $contract->getKey())
            ->where('party_id', $party->getKey())
            ->first();
    }
}
