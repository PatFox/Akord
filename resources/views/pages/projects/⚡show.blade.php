<?php

use App\Actions\Contracts\ContractReadiness;
use App\Actions\Organisations\CreateContract;
use App\Enums\ContractState;
use App\Enums\ContractStatus;
use App\Livewire\Concerns\RunsActions;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    use RunsActions;

    public Organisation $organisation;

    public Project $project;

    /** Which statuses to list: a ContractStatus value or "all". Set from ?status= on load (D14). */
    #[Locked]
    public string $filter = 'open';

    #[Validate('required|string|max:200')]
    public string $title = '';

    public bool $requireHumanSignoff = false;

    public function mount(Organisation $organisation, Project $project): void
    {
        abort_unless(Project::query()->visibleTo(Auth::user())->whereKey($project->id)->exists(), 404);

        $this->organisation = $organisation;
        $this->project = $project;

        $requested = request()->query('status');
        $this->filter = $requested === 'all' || ContractStatus::tryFrom(is_string($requested) ? $requested : '') !== null
            ? $requested
            : ContractStatus::Open->value;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->isOwnerOf($this->organisation->id);
    }

    /**
     * The latest visible version of each contract, any status.
     *
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function latest(): Collection
    {
        return Contract::query()
            ->latestVisibleTo(Auth::user())
            ->where('project_id', $this->project->id)
            ->orderBy('title')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, int> status value => number of contracts, plus "all"
     */
    #[Computed]
    public function counts(): array
    {
        $counts = ['all' => $this->latest->count()];
        foreach (ContractStatus::cases() as $status) {
            $counts[$status->value] = $this->latest->where('status', $status)->count();
        }

        return $counts;
    }

    /**
     * @return Collection<int, array{contract: Contract, state: ContractState}>
     */
    #[Computed]
    public function contracts(): Collection
    {
        return $this->latest
            ->when($this->filter !== 'all', fn (Collection $contracts) => $contracts->where('status', ContractStatus::from($this->filter)))
            ->values()
            ->map(fn (Contract $contract) => [
                'contract' => $contract,
                'state' => ContractReadiness::report($contract)->state,
            ]);
    }

    public function create(): void
    {
        $this->validate();

        $contract = $this->attempt(fn () => app(CreateContract::class)->handle(Auth::user(), $this->project, $this->title, $this->requireHumanSignoff));

        if ($contract !== null) {
            $this->redirectRoute('contracts.settings', ['contract' => $contract], navigate: true);
        }
    }

    public function render()
    {
        return $this->view()->title($this->project->name);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <x-slot:breadcrumbs>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.show', $organisation)" wire:navigate>{{ $organisation->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </x-slot:breadcrumbs>

    <div>
        <flux:heading size="xl" level="1">{{ $project->name }}</flux:heading>
    </div>

    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <flux:heading size="lg" level="2">{{ __('Contracts') }}</flux:heading>
            {{-- Plain links: changing the filter reloads the page. --}}
            <nav class="flex flex-wrap gap-1" aria-label="{{ __('Contract status') }}" data-test="status-filter">
                @foreach ([...array_map(fn ($s) => [$s->value, $s->label()], \App\Enums\ContractStatus::cases()), ['all', 'All']] as [$value, $label])
                    <flux:button size="sm" :variant="$filter === $value ? 'primary' : 'ghost'"
                                 :href="route('projects.show', ['organisation' => $organisation, 'project' => $project] + ($value === 'open' ? [] : ['status' => $value]))">
                        {{ __($label) }} <span class="ms-1 opacity-70">{{ $this->counts[$value] }}</span>
                    </flux:button>
                @endforeach
            </nav>
        </div>

        @forelse ($this->contracts as $item)
            <a href="{{ route('contracts.show', $item['contract']) }}" wire:navigate wire:key="contract-{{ $item['contract']->id }}"
               class="flex items-center justify-between gap-4 rounded-lg border border-zinc-200 px-4 py-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40">
                <span class="font-medium">
                    {{ $item['contract']->title }}
                    @if ($item['contract']->version > 1)
                        <span class="ms-1 text-xs font-normal text-zinc-500" title="{{ __(':count versions', ['count' => $item['contract']->version]) }}">v{{ $item['contract']->version }}</span>
                    @endif
                </span>
                <span class="flex items-center gap-2">
                    @if ($item['contract']->status !== \App\Enums\ContractStatus::Open)
                        <flux:badge size="sm" :color="$item['contract']->status->colour()">{{ __($item['contract']->status->label()) }}</flux:badge>
                    @endif
                    <flux:badge size="sm" :color="$item['state']->colour()">{{ $item['state']->label() }}</flux:badge>
                </span>
            </a>
        @empty
            <flux:text>
                @if ($this->counts['all'] === 0)
                    {{ $this->isOwner ? __('No contracts yet. Create the first one below.') : __('No contracts visible to you.') }}
                @else
                    {{ $filter === 'all' ? __('No contracts.') : __('No :status contracts.', ['status' => strtolower(\App\Enums\ContractStatus::from($filter)->label())]) }}
                @endif
            </flux:text>
        @endforelse
    </div>

    @if ($this->isOwner)
        <form wire:submit="create" class="space-y-3">
            <div class="flex items-end gap-3">
                <flux:input wire:model="title" :label="__('New contract')" :placeholder="__('Title')" class="flex-1" />
                <flux:button type="submit" variant="primary">{{ __('Create contract') }}</flux:button>
            </div>
            <flux:checkbox wire:model="requireHumanSignoff" :label="__('Require a person (not an agent) to sign off')" />
        </form>
    @endif
</section>
