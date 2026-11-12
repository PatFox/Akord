<?php

use App\Actions\Organisations\CreateParty;
use App\Actions\Organisations\SetOwnPartyMembership;
use App\Enums\PartyType;
use App\Livewire\Concerns\RunsActions;
use App\Models\Organisation;
use App\Models\Party;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use RunsActions;

    public Organisation $organisation;

    public string $name = '';

    public string $type = 'team';

    public bool $joinAsMember = true;

    public function mount(Organisation $organisation): void
    {
        abort_unless(Organisation::query()->visibleTo(Auth::user())->whereKey($organisation->id)->exists(), 404);
        abort_unless(Auth::user()->isOwnerOf($organisation->id), 403);

        $this->organisation = $organisation;
    }

    /**
     * @return Collection<int, Party>
     */
    #[Computed]
    public function parties(): Collection
    {
        return Party::query()
            ->where('organisation_id', $this->organisation->id)
            ->with(['users' => fn ($users) => $users->orderBy('name')])
            ->withCount('contracts')
            ->orderBy('type')
            ->orderBy('name')
            ->get();
    }

    public function create(): void
    {
        $validated = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::enum(PartyType::class)],
        ]);
        $type = PartyType::from($validated['type']);

        $created = $this->attempt(
            fn () => app(CreateParty::class)->handle(Auth::user(), $this->organisation, $this->name, $type, $this->joinAsMember && $type !== PartyType::Agent),
            __('Party created.'),
        );

        if ($created !== null) {
            $this->reset('name');
            unset($this->parties);
        }
    }

    public function setMembership(int $partyId, bool $member): void
    {
        $party = Party::query()->where('organisation_id', $this->organisation->id)->findOrFail($partyId);

        $this->attempt(fn () => app(SetOwnPartyMembership::class)->handle(Auth::user(), $party, $member));
        unset($this->parties);
    }

    public function render()
    {
        return $this->view()->title(__('Parties').' · '.$this->organisation->name);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.show', $organisation)" wire:navigate>{{ $organisation->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Parties') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1">{{ __('Parties') }}</flux:heading>
        <flux:subheading>{{ __('People, teams and agents that take part in contracts. Attach them to a contract from its settings.') }}</flux:subheading>
    </div>

    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('Party') }}</flux:table.column>
            <flux:table.column>{{ __('Type') }}</flux:table.column>
            <flux:table.column>{{ __('Members') }}</flux:table.column>
            <flux:table.column>{{ __('Contracts') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($this->parties as $party)
                @php($isMember = $party->users->contains('id', auth()->id()))
                <flux:table.row :key="'party-'.$party->id">
                    <flux:table.cell class="font-medium">{{ $party->name }}</flux:table.cell>
                    <flux:table.cell><flux:badge size="sm" :color="$party->isAgent() ? 'purple' : 'zinc'">{{ $party->type->label() }}</flux:badge></flux:table.cell>
                    <flux:table.cell>{{ $party->isAgent() ? '—' : ($party->users->pluck('name')->join(', ') ?: __('None')) }}</flux:table.cell>
                    <flux:table.cell>{{ $party->contracts_count }}</flux:table.cell>
                    <flux:table.cell align="end">
                        @if ($party->isAgent())
                            <flux:button size="xs" icon="key" :href="route('organisations.agent', ['organisation' => $organisation, 'party' => $party])" wire:navigate>{{ __('Agent access') }}</flux:button>
                        @else
                            <flux:button size="xs" variant="{{ $isMember ? 'ghost' : 'filled' }}" wire:click="setMembership({{ $party->id }}, {{ $isMember ? 'false' : 'true' }})">
                                {{ $isMember ? __('Leave') : __('Join') }}
                            </flux:button>
                        @endif
                    </flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>

    <form wire:submit="create" class="grid gap-4 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end">
        <flux:input wire:model="name" :label="__('New party')" :placeholder="__('Name')" />
        <flux:select wire:model.live="type" :label="__('Type')">
            @foreach (\App\Enums\PartyType::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        @if ($type !== 'agent')
            <flux:checkbox wire:model="joinAsMember" :label="__('I am a member')" class="sm:mb-2" />
        @else
            <span></span>
        @endif
        <flux:button type="submit" variant="primary">{{ __('Create party') }}</flux:button>
    </form>
    <flux:text size="sm">{{ __('Other people join parties by invitation, and agents connect with a claim code.') }}</flux:text>
</section>
