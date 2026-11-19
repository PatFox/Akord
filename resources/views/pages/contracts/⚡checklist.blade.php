<?php

use App\Actions\Checklists\SetChecklistItemChecked;
use App\Enums\ContractAbility;
use App\Livewire\Concerns\ActsOnContract;
use App\Livewire\Concerns\RunsActions;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\Contract;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * The contract page's Checklist tab: the version's working checklist, ticked off as the work is done. Agents write
 * it (create_checklist); people and agents who may edit tick items, also after agreement, until the contract is
 * complete or cancelled.
 */
new class extends Component {
    use ActsOnContract, RunsActions;

    public Contract $contract;

    public function mount(Contract $contract): void
    {
        Gate::authorize('view', $contract);
        $this->contract = $contract;
        $this->initialiseActingParty();
    }

    #[Computed]
    public function checklist(): ?Checklist
    {
        return Checklist::query()->where('contract_id', $this->contract->id)
            ->with(['createdByParty', 'createdByUser', 'stages.items' => fn ($items) => $items->with(['checkedByParty', 'checkedByUser', 'step.currentRevision'])])
            ->first();
    }

    #[Computed]
    public function canTick(): bool
    {
        return $this->contract->isOpen() && $this->allows(ContractAbility::Edit);
    }

    public function toggle(string $itemId): void
    {
        $item = ChecklistItem::query()->find($itemId);
        if ($item === null) {
            $this->refused(__('That item no longer exists.'), 404);

            return;
        }

        if ($this->attempt(fn () => app(SetChecklistItemChecked::class)->handle($this->actor(), $this->contract, $itemId, ! $item->isChecked())) !== null) {
            $this->dispatch('checklist-updated');
        }
    }

    /**
     * Someone ticked, added or replaced (checklist-refresh), or the status changed (steps-refresh).
     */
    #[On('checklist-refresh')]
    #[On('steps-refresh')]
    public function refreshChecklist(): void {}

    public function render()
    {
        unset($this->checklist, $this->canTick);

        return $this->view();
    }
}; ?>

<div data-test="checklist">
    @if ($this->checklist === null)
        <flux:card><flux:text>{{ __('This version has no checklist.') }}</flux:text></flux:card>
    @else
        @php($items = $this->checklist->stages->flatMap->items)
        @php($done = $items->whereNotNull('checked_at')->count())
        <flux:card class="space-y-5">
            <div class="space-y-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <flux:heading size="lg" level="2">{{ __(':done of :total done', ['done' => $done, 'total' => $items->count()]) }}</flux:heading>
                    <flux:text size="sm">
                        {{ __('Written by :who, :when', ['who' => $this->checklist->createdByUser?->name ?? $this->checklist->createdByParty->name, 'when' => $this->checklist->created_at?->diffForHumans()]) }}
                    </flux:text>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $items->count() }}" aria-valuenow="{{ $done }}">
                    <div class="h-full rounded-full bg-green-500" style="width: {{ $items->isEmpty() ? 0 : round(100 * $done / $items->count()) }}%"></div>
                </div>
                @unless ($contract->isOpen())
                    <flux:text size="sm">{{ __('The contract is marked :status, so the checklist is read-only.', ['status' => strtolower(__($contract->status->label()))]) }}</flux:text>
                @endunless
            </div>

            @foreach ($this->checklist->stages as $stage)
                @php($stageDone = $stage->items->whereNotNull('checked_at')->count())
                <section class="space-y-1" wire:key="stage-{{ $stage->id }}" data-test="checklist-stage">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 pb-1 dark:border-zinc-700">
                        <h3 class="font-semibold">{{ $stage->position }}. {{ $stage->title }}</h3>
                        <flux:badge size="sm" :color="$stage->items->isNotEmpty() && $stageDone === $stage->items->count() ? 'green' : 'zinc'">{{ $stageDone }}/{{ $stage->items->count() }}</flux:badge>
                    </div>

                    @forelse ($stage->items as $item)
                        <div class="flex items-start gap-3 py-1.5" wire:key="item-{{ $item->id }}" data-test="checklist-item">
                            <input type="checkbox" class="mt-1 size-4 shrink-0 accent-green-600 disabled:opacity-60"
                                   @checked($item->isChecked()) @disabled(! $this->canTick)
                                   wire:click="toggle('{{ $item->id }}')" aria-label="{{ $item->title }}">
                            <div class="min-w-0 flex-1 space-y-1">
                                <div @class(['text-sm', 'text-zinc-500 line-through' => $item->isChecked()])>{{ $item->title }}</div>
                                @if ($item->notes)
                                    <x-markdown :source="$item->notes" class="text-zinc-600 dark:text-zinc-300" />
                                @endif
                                <flux:text size="xs" class="flex flex-wrap gap-x-3">
                                    @if ($item->step)
                                        <a href="{{ route('contracts.show', $contract) }}#step-{{ $item->step_id }}" class="underline decoration-dotted underline-offset-2 hover:text-zinc-800 dark:hover:text-zinc-200">
                                            {{ __('Step: :title', ['title' => $item->step->currentRevision?->title]) }}
                                        </a>
                                    @endif
                                    @if ($item->isChecked())
                                        <span>{{ __('Ticked by :who, :when', ['who' => $item->checkedByUser?->name ?? $item->checkedByParty?->name ?? __('someone'), 'when' => $item->checked_at?->diffForHumans()]) }}</span>
                                    @endif
                                </flux:text>
                            </div>
                        </div>
                    @empty
                        <flux:text size="sm" class="py-1">{{ __('No items.') }}</flux:text>
                    @endforelse
                </section>
            @endforeach
        </flux:card>
    @endif
</div>
