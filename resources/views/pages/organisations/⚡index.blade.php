<?php

use App\Actions\Organisations\CreateOrganisation;
use App\Models\Organisation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Title('Organisations')] class extends Component {
    #[Validate('required|string|max:120')]
    public string $name = '';

    /**
     * @return Collection<int, array{organisation: Organisation, owner: bool}>
     */
    #[Computed]
    public function organisations(): Collection
    {
        $user = Auth::user();

        return Organisation::query()->visibleTo($user)->orderBy('name')->get()
            ->map(fn (Organisation $organisation) => [
                'organisation' => $organisation,
                'owner' => $user->isOwnerOf($organisation->id),
            ]);
    }

    public function create(): void
    {
        $this->validate();

        $organisation = app(CreateOrganisation::class)->handle(Auth::user(), $this->name);

        $this->redirectRoute('organisations.show', ['organisation' => $organisation], navigate: true);
    }
}; ?>

<section class="w-full max-w-3xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Organisations') }}</flux:heading>
        <flux:subheading>{{ __('Organisations you own, and those where you have been invited to a contract.') }}</flux:subheading>
    </div>

    @if ($this->organisations->isEmpty())
        <flux:callout icon="building-office" :heading="__('No organisations yet')">
            <flux:callout.text>{{ __('Create one below, or ask an owner to invite you to a contract.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="grid gap-3">
            @foreach ($this->organisations as $item)
                <a href="{{ route('organisations.show', $item['organisation']) }}" wire:navigate wire:key="org-{{ $item['organisation']->id }}"
                   class="flex items-center justify-between rounded-lg border border-zinc-200 px-4 py-3 hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-700/40">
                    <span class="font-medium">{{ $item['organisation']->name }}</span>
                    <flux:badge size="sm" :color="$item['owner'] ? 'indigo' : 'zinc'">{{ $item['owner'] ? __('Owner') : __('Guest') }}</flux:badge>
                </a>
            @endforeach
        </div>
    @endif

    <form wire:submit="create" class="flex items-end gap-3">
        <flux:input wire:model="name" :label="__('New organisation')" :placeholder="__('Name')" class="flex-1" />
        <flux:button type="submit" variant="primary">{{ __('Create') }}</flux:button>
    </form>
</section>
