<?php

namespace App\Livewire\Concerns;

use App\Enums\ContractAbility;
use App\Enums\ContractRole;
use App\Models\Contract;
use App\Models\Party;
use App\Support\ActingParties;
use App\Support\Actor;
use App\Support\ContractAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * For pages about one contract: re-checks visibility and reloads the contract on
 * every request, and tracks which party the person acts for (validated server-side;
 * the public property only drives the selector).
 *
 * @property Contract $contract
 */
trait ActsOnContract
{
    public ?int $actingPartyId = null;

    public function hydrateActsOnContract(): void
    {
        $this->contract->refresh();
        Gate::authorize('view', $this->contract);
    }

    protected function initialiseActingParty(): void
    {
        $this->actingPartyId = ActingParties::current(Auth::user(), $this->contract)?->id;
    }

    public function updatedActingPartyId(mixed $value): void
    {
        if (! is_numeric($value) || ! ActingParties::choose(Auth::user(), $this->contract, (int) $value)) {
            $this->refused(__('You cannot act for that party on this contract.'), 403);
        }

        $this->actingPartyId = ActingParties::current(Auth::user(), $this->contract)?->id;
    }

    protected function actor(): Actor
    {
        return ActingParties::actor(Auth::user(), $this->contract);
    }

    protected function allows(ContractAbility $ability): bool
    {
        return ContractAccess::allows($this->actor(), $this->contract, $ability);
    }

    /**
     * @return Collection<int, array{party: Party, role: ContractRole|null}>
     */
    #[Computed]
    public function actingOptions(): Collection
    {
        return ActingParties::options(Auth::user(), $this->contract);
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->isOwnerOf($this->contract->organisationId());
    }
}
