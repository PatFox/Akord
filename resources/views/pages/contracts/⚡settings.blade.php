<?php

use App\Actions\Contracts\AttachParty;
use App\Actions\Contracts\DetachParty;
use App\Actions\Contracts\SetHumanSignOffRequired;
use App\Actions\Contracts\SetRequiredApprover;
use App\Actions\Contracts\StartNewVersion;
use App\Enums\ContractRole;
use App\Livewire\Concerns\ActsOnContract;
use App\Livewire\Concerns\RunsActions;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Party;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use ActsOnContract, RunsActions;

    public Contract $contract;

    /** @var array<int|string, string> party id => role, bound to the role selects */
    public array $roles = [];

    public ?int $attachPartyId = null;

    public string $attachRole = 'commenter';

    public bool $requireHumanSignoff = false;

    public function mount(Contract $contract): void
    {
        Gate::authorize('administer', $contract);
        $this->contract = $contract;
        $this->initialiseActingParty();
        $this->syncForm();
    }

    public function hydrate(): void
    {
        Gate::authorize('administer', $this->contract);
    }

    private function syncForm(): void
    {
        unset($this->memberships, $this->attachable);
        $this->roles = $this->memberships->mapWithKeys(fn (ContractParty $m) => [$m->party_id => $m->role->value])->all();
        $this->requireHumanSignoff = $this->contract->require_human_signoff;
        $this->attachPartyId = $this->attachable->first()?->id;
    }

    /**
     * @return Collection<int, ContractParty>
     */
    #[Computed]
    public function memberships(): Collection
    {
        return ContractParty::query()
            ->where('contract_id', $this->contract->id)
            ->get()
            ->each(fn (ContractParty $m) => $m->setRelation('party', Party::query()->withCount('users')->find($m->party_id)))
            ->sortBy(fn (ContractParty $m) => $m->party?->name)
            ->values();
    }

    /**
     * @return Collection<int, Party>
     */
    #[Computed]
    public function attachable(): Collection
    {
        return Party::query()
            ->where('organisation_id', $this->contract->organisationId())
            ->whereNotIn('id', ContractParty::query()->where('contract_id', $this->contract->id)->select('party_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Contract> oldest first
     */
    #[Computed]
    public function versions(): Collection
    {
        $versions = collect([$this->contract]);
        for ($c = $this->contract; $c->previous_version_id && ($c = Contract::query()->find($c->previous_version_id)); ) {
            $versions->prepend($c);
        }
        for ($c = $this->contract; $c = Contract::query()->where('previous_version_id', $c->id)->first(); ) {
            $versions->push($c);
        }

        return $versions;
    }

    public function updatedRoles(mixed $value, int|string $partyId): void
    {
        $role = ContractRole::tryFrom((string) $value);
        $party = Party::query()->find((int) $partyId);

        if ($role !== null && $party !== null) {
            $this->attempt(fn () => app(AttachParty::class)->handle($this->actor(), $this->contract, $party, $role), __('Role updated.'));
        }

        $this->syncForm();
    }

    public function setRequired(int $partyId, bool $required): void
    {
        $party = Party::query()->findOrFail($partyId);
        $this->attempt(fn () => app(SetRequiredApprover::class)->handle($this->actor(), $this->contract, $party, $required));
        $this->syncForm();
    }

    public function detach(int $partyId): void
    {
        $party = Party::query()->findOrFail($partyId);
        $this->attempt(fn () => app(DetachParty::class)->handle($this->actor(), $this->contract, $party), __('Party detached.'));
        $this->syncForm();
    }

    public function attach(): void
    {
        $this->validate([
            'attachPartyId' => ['required', 'integer'],
            'attachRole' => ['required', Rule::enum(ContractRole::class)],
        ]);

        $party = Party::query()->findOrFail($this->attachPartyId);
        $this->attempt(fn () => app(AttachParty::class)->handle($this->actor(), $this->contract, $party, ContractRole::from($this->attachRole)), __('Party attached.'));
        $this->syncForm();
    }

    public function updatedRequireHumanSignoff(bool $value): void
    {
        $this->attempt(fn () => app(SetHumanSignOffRequired::class)->handle($this->actor(), $this->contract, $value), __('Saved.'));
        $this->contract->refresh();
        $this->syncForm();
    }

    public function startNewVersion(): void
    {
        $next = $this->attempt(fn () => app(StartNewVersion::class)->handle($this->actor(), $this->contract));

        if ($next !== null) {
            $this->redirectRoute('contracts.settings', ['contract' => $next], navigate: true);
        }
    }

    public function render()
    {
        return $this->view()->title(__('Settings').' · '.$this->contract->title);
    }
}; ?>

<section class="w-full max-w-5xl space-y-8">
    <div>
        <x-contract-breadcrumbs :contract="$contract" :current="__('Settings')" />
        <flux:heading size="xl" level="1">{{ __('Contract settings') }}</flux:heading>
        @if ($contract->isAgreed())
            <flux:text class="mt-1">{{ __('This version is agreed and locked; start a new version to change parties or rules.') }}</flux:text>
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Parties') }}</flux:heading>
        <flux:text size="sm">{{ __('Editors change steps; editors and commenters comment and approve; viewers only read. Required approvers must approve every step and sign off.') }}</flux:text>

        <flux:table>
            <flux:table.columns>
                <flux:table.column>{{ __('Party') }}</flux:table.column>
                <flux:table.column>{{ __('Role') }}</flux:table.column>
                <flux:table.column>{{ __('Required approver') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @forelse ($this->memberships as $membership)
                    <flux:table.row :key="'membership-'.$membership->id">
                        <flux:table.cell>
                            <span class="font-medium">{{ $membership->party->name }}</span>
                            <flux:text size="sm">{{ $membership->party->type->label() }}@unless ($membership->party->isAgent()) · {{ trans_choice(':count member|:count members', $membership->party->users_count) }}@endunless</flux:text>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:select wire:model.live="roles.{{ $membership->party_id }}" size="sm" class="max-w-36" :disabled="$contract->isAgreed()">
                                @foreach (\App\Enums\ContractRole::cases() as $role)
                                    <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        </flux:table.cell>
                        <flux:table.cell>
                            <flux:button size="xs" :variant="$membership->is_required_approver ? 'primary' : 'ghost'" :icon="$membership->is_required_approver ? 'check' : null"
                                         wire:click="setRequired({{ $membership->party_id }}, {{ $membership->is_required_approver ? 'false' : 'true' }})"
                                         :disabled="$contract->isAgreed()">
                                {{ $membership->is_required_approver ? __('Required') : __('Not required') }}
                            </flux:button>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:button size="xs" variant="ghost" icon="x-mark" wire:click="detach({{ $membership->party_id }})" :disabled="$contract->isAgreed()">{{ __('Detach') }}</flux:button>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="4">{{ __('No parties attached yet.') }}</flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>

        @if (! $contract->isAgreed())
            @if ($this->attachable->isNotEmpty())
                <form wire:submit="attach" class="flex flex-wrap items-end gap-3">
                    <flux:select wire:model="attachPartyId" :label="__('Attach party')" class="max-w-64">
                        @foreach ($this->attachable as $party)
                            <flux:select.option :value="$party->id">{{ $party->name }} ({{ $party->type->label() }})</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="attachRole" :label="__('Role')" class="max-w-40">
                        @foreach (\App\Enums\ContractRole::cases() as $role)
                            <flux:select.option :value="$role->value">{{ $role->label() }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:button type="submit" variant="primary">{{ __('Attach') }}</flux:button>
                </form>
            @else
                <flux:text size="sm">
                    {{ __('Every party of the organisation is attached.') }}
                    <flux:link :href="route('organisations.parties', $contract->project->organisation)" wire:navigate>{{ __('Create a party') }}</flux:link>
                </flux:text>
            @endif
        @endif
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Sign-off rules') }}</flux:heading>
        <flux:switch wire:model.live="requireHumanSignoff" :label="__('Require a person to sign off')" :description="__('When on, agent parties can approve steps but cannot sign off the contract.')" :disabled="$contract->isAgreed()" />
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Versions') }}</flux:heading>
        <div class="flex flex-wrap gap-2">
            @foreach ($this->versions as $version)
                <flux:button size="sm" :variant="$version->id === $contract->id ? 'primary' : 'ghost'" :href="route('contracts.show', $version)" wire:navigate wire:key="version-{{ $version->id }}">
                    v{{ $version->version }} · {{ $version->isAgreed() ? __('agreed') : __('draft') }}
                </flux:button>
            @endforeach
        </div>
        @if ($contract->isAgreed() && $this->versions->last()->id === $contract->id)
            <flux:button variant="primary" wire:click="startNewVersion" wire:confirm="{{ __('Start a new version? It copies the current content; approvals and sign-offs start again.') }}">{{ __('Start new version') }}</flux:button>
        @endif
    </div>
</section>
