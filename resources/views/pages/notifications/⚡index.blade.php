<?php

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Notifications')] class extends Component {
    /**
     * @return Collection<int, DatabaseNotification>
     */
    #[Computed]
    public function notifications(): Collection
    {
        return Auth::user()->notifications()->limit(100)->get();
    }

    /**
     * Marks one notification read and goes where it points. The page there re-checks access.
     */
    public function open(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();

        $this->redirect((string) ($notification->data['url'] ?? route('dashboard')), navigate: true);
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications()->update(['read_at' => now()]);
        unset($this->notifications);
    }
}; ?>

<section class="w-full max-w-3xl space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">{{ __('Notifications') }}</flux:heading>
            <flux:subheading>{{ __('Changes on contracts you take part in. Verified email addresses get these by email too.') }}</flux:subheading>
        </div>
        @if ($this->notifications->contains(fn ($notification) => $notification->read_at === null))
            <flux:button size="sm" icon="check" wire:click="markAllRead">{{ __('Mark all as read') }}</flux:button>
        @endif
    </div>

    @if ($this->notifications->isEmpty())
        <flux:callout icon="bell" :heading="__('Nothing yet')">
            <flux:callout.text>{{ __('You will hear here about comments, edits, sign-offs and agreements on your contracts.') }}</flux:callout.text>
        </flux:callout>
    @else
        <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->notifications as $notification)
                <button type="button" wire:click="open('{{ $notification->id }}')" wire:key="notification-{{ $notification->id }}" data-test="notification"
                        @class([
                            'flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-zinc-50 dark:hover:bg-zinc-700/40',
                            'bg-sky-50/60 dark:bg-sky-900/20' => $notification->read_at === null,
                        ])>
                    <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-sky-500' => $notification->read_at === null, 'bg-transparent' => $notification->read_at !== null])></span>
                    <span class="min-w-0 flex-1 space-y-0.5">
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $notification->data['contract'] ?? '' }} · {{ $notification->created_at?->diffForHumans() }}</span>
                        <span @class(['block text-sm', 'font-medium' => $notification->read_at === null])>{{ $notification->data['message'] ?? '' }}</span>
                        @if (! empty($notification->data['detail']))
                            <span class="block text-sm text-zinc-600 dark:text-zinc-300">{{ $notification->data['detail'] }}</span>
                        @endif
                    </span>
                </button>
            @endforeach
        </div>
    @endif
</section>
