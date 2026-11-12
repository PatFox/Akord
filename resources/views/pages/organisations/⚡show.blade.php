<?php

use App\Actions\Organisations\CreateProject;
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

    #[Validate('required|string|max:120')]
    public string $projectName = '';

    public function mount(Organisation $organisation): void
    {
        abort_unless(Organisation::query()->visibleTo(Auth::user())->whereKey($organisation->id)->exists(), 404);

        $this->organisation = $organisation;
    }

    #[Computed]
    public function isOwner(): bool
    {
        return Auth::user()->isOwnerOf($this->organisation->id);
    }

    /**
     * @return Collection<int, array{project: Project, contracts: int}>
     */
    #[Computed]
    public function projects(): Collection
    {
        $user = Auth::user();

        return Project::query()
            ->visibleTo($user)
            ->where('organisation_id', $this->organisation->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project) => [
                'project' => $project,
                'contracts' => Contract::query()->visibleTo($user)->where('project_id', $project->id)->count(),
            ]);
    }

    /**
     * @return Collection<int, Organisation>
     */
    #[Computed]
    public function otherOrganisations(): Collection
    {
        return Organisation::query()->visibleTo(Auth::user())->whereKeyNot($this->organisation->id)->orderBy('name')->get();
    }

    public function createProject(): void
    {
        $this->validate();

        $project = $this->attempt(fn () => app(CreateProject::class)->handle(Auth::user(), $this->organisation, $this->projectName));

        if ($project !== null) {
            $this->redirectRoute('projects.show', ['organisation' => $this->organisation, 'project' => $project], navigate: true);
        }
    }

    public function render()
    {
        return $this->view()->title($this->organisation->name);
    }
}; ?>

<section class="w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:breadcrumbs class="mb-2">
                <flux:breadcrumbs.item :href="route('dashboard')" wire:navigate>{{ __('Organisations') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $organisation->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1">{{ $organisation->name }}</flux:heading>
        </div>

        <div class="flex items-center gap-2">
            @if ($this->otherOrganisations->isNotEmpty())
                <flux:dropdown>
                    <flux:button icon:trailing="chevron-down" size="sm">{{ __('Switch organisation') }}</flux:button>
                    <flux:menu>
                        @foreach ($this->otherOrganisations as $other)
                            <flux:menu.item :href="route('organisations.show', $other)" wire:navigate>{{ $other->name }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif
            @if ($this->isOwner)
                <flux:button size="sm" icon="user-group" :href="route('organisations.parties', $organisation)" wire:navigate>{{ __('Parties') }}</flux:button>
            @endif
        </div>
    </div>

    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Projects') }}</flux:heading>

        @forelse ($this->projects as $item)
            <a href="{{ route('projects.show', ['organisation' => $organisation, 'project' => $item['project']]) }}" wire:navigate wire:key="project-{{ $item['project']->id }}"
               class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40">
                <span class="font-medium">{{ $item['project']->name }}</span>
                <flux:text size="sm">{{ trans_choice(':count contract|:count contracts', $item['contracts']) }}</flux:text>
            </a>
        @empty
            <flux:text>{{ $this->isOwner ? __('No projects yet. Create the first one below.') : __('No projects visible to you.') }}</flux:text>
        @endforelse
    </div>

    @if ($this->isOwner)
        <form wire:submit="createProject" class="flex items-end gap-3">
            <flux:input wire:model="projectName" :label="__('New project')" :placeholder="__('Name')" class="flex-1" />
            <flux:button type="submit" variant="primary">{{ __('Create project') }}</flux:button>
        </form>
    @endif
</section>
