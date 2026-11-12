<?php

use App\Actions\Contracts\ContractReadiness;
use App\Actions\Organisations\CreateContract;
use App\Enums\ContractState;
use App\Livewire\Concerns\RunsActions;
use App\Models\Contract;
use App\Models\Organisation;
use App\Models\Project;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component {
    use RunsActions;

    public Organisation $organisation;

    public Project $project;

    #[Validate('required|string|max:200')]
    public string $title = '';

    public bool $requireHumanSignoff = false;

    public function mount(Organisation $organisation, Project $project): void
    {
        abort_unless(Project::query()->visibleTo(Auth::user())->whereKey($project->id)->exists(), 404);

        $this->organisation = $organisation;
        $this->project = $project;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->isOwnerOf($this->organisation->id);
    }

    /**
     * @return Collection<int, array{contract: Contract, state: ContractState, superseded: bool}>
     */
    #[Computed]
    public function contracts(): Collection
    {
        return Contract::query()
            ->visibleTo(Auth::user())
            ->where('project_id', $this->project->id)
            ->withExists('nextVersion')
            ->orderBy('title')
            ->orderByDesc('version')
            ->get()
            ->map(fn (Contract $contract) => [
                'contract' => $contract,
                'state' => ContractReadiness::report($contract)->state,
                'superseded' => (bool) $contract->next_version_exists,
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
    <div>
        <flux:breadcrumbs class="mb-2">
            <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item :href="route('organisations.show', $organisation)" wire:navigate>{{ $organisation->name }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $project->name }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>
        <flux:heading size="xl" level="1">{{ $project->name }}</flux:heading>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Contracts') }}</flux:heading>

        @forelse ($this->contracts as $item)
            <a href="{{ route('contracts.show', $item['contract']) }}" wire:navigate wire:key="contract-{{ $item['contract']->id }}"
               @class(['flex items-center justify-between gap-4 rounded-lg border border-zinc-200 px-4 py-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40', 'opacity-60' => $item['superseded']])>
                <span class="font-medium">{{ $item['contract']->title }} <span class="text-zinc-500">v{{ $item['contract']->version }}</span></span>
                <span class="flex items-center gap-2">
                    @if ($item['superseded'])
                        <flux:badge size="sm" color="zinc">{{ __('Superseded') }}</flux:badge>
                    @endif
                    <flux:badge size="sm" :color="$item['state']->colour()">{{ $item['state']->label() }}</flux:badge>
                </span>
            </a>
        @empty
            <flux:text>{{ $this->isOwner ? __('No contracts yet. Create the first one below.') : __('No contracts visible to you.') }}</flux:text>
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
