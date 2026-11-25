<?php

use App\Actions\Checklists\AddChecklistItem;
use App\Actions\Checklists\DeleteChecklistItem;
use App\Actions\Checklists\MoveChecklistItem;
use App\Actions\Checklists\SetChecklistAssignee;
use App\Actions\Checklists\SetChecklistItemChecked;
use App\Actions\Checklists\UpdateChecklistItem;
use App\Enums\ContractAbility;
use App\Livewire\Concerns\ActsOnContract;
use App\Livewire\Concerns\RunsActions;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistStage;
use App\Models\Contract;
use App\Models\ContractParty;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;
use App\Support\ChecklistItemOrder;
use App\Support\ContractAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
 * One of the contract page's Checklist tabs: a named working checklist, ticked off as the work is done. Agents write
 * them (create_checklist); people and agents who may edit tick items, and so do the parties an owner assigns to the
 * checklist whatever their role (D18), also after agreement, until the contract is complete or cancelled. Whoever may
 * edit also changes, reorders, moves and deletes items here.
 */
new class extends Component {
    use ActsOnContract, RunsActions;

    public Contract $contract;

    public string $checklistId;

    public ?string $assignPartyId = null;

    /** Which items to list: "all", or "open" (not ticked yet); ?items= in the URL, like the steps filter (?show=). */
    #[Url(as: 'items', except: 'all')]
    public string $itemFilter = 'all';

    /** The item open in the inline editor, if any. */
    public ?string $editingItemId = null;

    public string $itemTitle = '';

    public string $itemNotes = '';

    /** '' for no linked step. */
    public string $itemStepId = '';

    /** The stage whose "Add item" form is open, if any; the form stays open to add several in a row. */
    public ?string $addingToStageId = null;

    public string $newItemTitle = '';

    public string $newItemNotes = '';

    /** '' for no linked step. */
    public string $newItemStepId = '';

    public function mount(Contract $contract, string $checklistId): void
    {
        Gate::authorize('view', $contract);
        $this->contract = $contract;
        $this->checklistId = $checklistId;
        $this->initialiseActingParty();
    }

    /**
     * Null when it was replaced (under a new id) or never belonged to this contract.
     */
    #[Computed]
    public function checklist(): ?Checklist
    {
        return Checklist::query()->where('contract_id', $this->contract->id)->whereKey($this->checklistId)
            ->with([
                'createdByParty', 'createdByUser', 'assignedParties' => fn ($parties) => $parties->orderBy('name'),
                'stages.items' => fn ($items) => $items->with(['checkedByParty', 'checkedByUser', 'step.currentRevision']),
            ])
            ->first();
    }

    #[Computed]
    public function canTick(): bool
    {
        return $this->checklist !== null && $this->contract->isOpen() && ContractAccess::allowsTick($this->actor(), $this->contract, $this->checklist);
    }

    #[Computed]
    public function canAssign(): bool
    {
        return $this->isOwner && $this->contract->isOpen();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return $this->checklist !== null && $this->contract->isOpen() && $this->allows(ContractAbility::Edit);
    }

    /**
     * The version's active steps, in contract order, for an item's step link.
     *
     * @return Collection<int, Step>
     */
    #[Computed]
    public function stepOptions(): Collection
    {
        return $this->contract->phases()
            ->with(['steps' => fn ($steps) => $steps->active()->with('currentRevision')])
            ->get()
            ->flatMap(fn (Phase $phase) => $phase->steps)
            ->values();
    }

    /**
     * Parties attached to the contract that are not assigned to this checklist yet.
     *
     * @return Collection<int, Party>
     */
    #[Computed]
    public function assignable(): Collection
    {
        return Party::query()
            ->whereIn('id', ContractParty::query()->where('contract_id', $this->contract->id)->select('party_id'))
            ->whereNotIn('id', $this->checklist?->assignedParties->modelKeys() ?? [])
            ->orderBy('name')
            ->get();
    }

    public function assign(): void
    {
        $party = Party::query()->find($this->assignPartyId);
        if ($party === null) {
            $this->refused(__('Choose a party to assign.'), 422);

            return;
        }

        $this->attempt(fn () => app(SetChecklistAssignee::class)->handle($this->actor(), $this->contract, $this->checklistId, $party, true), __('Party assigned.'));
    }

    public function unassign(string $partyId): void
    {
        $party = Party::query()->findOrFail($partyId);
        $this->attempt(fn () => app(SetChecklistAssignee::class)->handle($this->actor(), $this->contract, $this->checklistId, $party, false), __('Party removed from the checklist.'));
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

    public function editItem(string $itemId): void
    {
        $item = $this->stageOf($itemId)?->items->firstWhere('id', $itemId);
        if ($item === null) {
            $this->refused(__('That item no longer exists.'), 404);

            return;
        }

        $this->resetValidation();
        $this->editingItemId = $item->id;
        $this->itemTitle = $item->title;
        $this->itemNotes = (string) $item->notes;
        $this->itemStepId = (string) $item->step_id;
    }

    public function startAddingItem(string $stageId): void
    {
        $this->cancelAddItem();
        $this->addingToStageId = $stageId;
    }

    public function cancelAddItem(): void
    {
        $this->reset('addingToStageId', 'newItemTitle', 'newItemNotes', 'newItemStepId');
        $this->resetValidation();
    }

    /**
     * Adds the item at the end of the stage, then clears the form for the next one.
     */
    public function addItem(): void
    {
        if ($this->addingToStageId === null) {
            return;
        }

        $this->validate([
            'newItemTitle' => ['required', 'string', 'max:300'],
            'newItemNotes' => ['nullable', 'string', 'max:5000'],
            'newItemStepId' => ['nullable', 'string'],
        ], attributes: ['newItemTitle' => __('title'), 'newItemNotes' => __('notes')]);

        $stageId = $this->addingToStageId;
        $added = $this->attempt(fn () => app(AddChecklistItem::class)->handle(
            $this->actor(), $this->contract, $stageId, trim($this->newItemTitle), $this->newItemNotes, $this->newItemStepId,
        ), __('Item added.'));

        if ($added !== null) {
            $this->reset('newItemTitle', 'newItemNotes', 'newItemStepId');
            $this->dispatch('checklist-updated');
        }
    }

    public function cancelEditItem(): void
    {
        $this->reset('editingItemId', 'itemTitle', 'itemNotes', 'itemStepId');
        $this->resetValidation();
    }

    /**
     * The action changes only what differs, and empty notes or step clear them. The step goes only when it changed,
     * so an item still linked to a step archived since can be saved.
     */
    public function saveItem(): void
    {
        if ($this->editingItemId === null) {
            return;
        }

        $this->validate([
            'itemTitle' => ['required', 'string', 'max:300'],
            'itemNotes' => ['nullable', 'string', 'max:5000'],
            'itemStepId' => ['nullable', 'string'],
        ], attributes: ['itemTitle' => __('title'), 'itemNotes' => __('notes')]);

        $itemId = $this->editingItemId;
        $item = $this->stageOf($itemId)?->items->firstWhere('id', $itemId);
        $changes = ['title' => trim($this->itemTitle), 'notes' => $this->itemNotes];
        if ($this->itemStepId !== (string) $item?->step_id) {
            $changes['step_id'] = $this->itemStepId;
        }

        $saved = $this->attempt(fn () => app(UpdateChecklistItem::class)->handle($this->actor(), $this->contract, $itemId, $changes), __('Item saved.'));

        if ($saved !== null) {
            $this->cancelEditItem();
            $this->dispatch('checklist-updated');
        }
    }

    /**
     * One place up (-1) or down (1) within the item's stage.
     */
    public function moveItem(string $itemId, int $offset): void
    {
        $siblings = $this->stageOf($itemId)?->items->modelKeys() ?? [];
        $index = array_search($itemId, $siblings, true);
        if ($index === false) {
            $this->refused(__('That item no longer exists.'), 404);

            return;
        }

        $target = $index + ($offset < 0 ? -1 : 1);
        if ($target < 0 || $target >= count($siblings)) {
            return;
        }

        $others = array_values(array_diff($siblings, [$itemId]));
        $this->move($itemId, null, $target === 0 ? ChecklistItemOrder::START : $others[$target - 1]);
    }

    /**
     * To the end of another stage of this checklist.
     */
    public function moveItemTo(string $itemId, string $stageId): void
    {
        $this->move($itemId, $stageId, null);
    }

    public function deleteItem(string $itemId): void
    {
        $deleted = $this->attempt(function () use ($itemId): bool {
            app(DeleteChecklistItem::class)->handle($this->actor(), $this->contract, $itemId);

            return true;
        }, __('Item deleted.'));

        if ($deleted === true) {
            if ($this->editingItemId === $itemId) {
                $this->cancelEditItem();
            }
            $this->dispatch('checklist-updated');
        }
    }

    private function move(string $itemId, ?string $stageId, ?string $afterItemId): void
    {
        if ($this->attempt(fn () => app(MoveChecklistItem::class)->handle($this->actor(), $this->contract, $itemId, $stageId, $afterItemId)) !== null) {
            $this->dispatch('checklist-updated');
        }
    }

    private function stageOf(string $itemId): ?ChecklistStage
    {
        return $this->checklist?->stages->first(fn (ChecklistStage $stage) => $stage->items->contains('id', $itemId));
    }

    /**
     * Someone ticked, added or replaced (checklist-refresh), or the status changed (steps-refresh).
     */
    #[On('checklist-refresh')]
    #[On('steps-refresh')]
    public function refreshChecklist(): void {}

    public function render()
    {
        unset($this->checklist, $this->canTick, $this->canAssign, $this->canEdit, $this->assignable, $this->stepOptions);
        if (! in_array($this->itemFilter, ['all', 'open'], true)) {
            $this->itemFilter = 'all';
        }
        // The item being edited may have been deleted by someone else.
        if ($this->editingItemId !== null && $this->stageOf($this->editingItemId) === null) {
            $this->cancelEditItem();
        }
        // So may the stage being added to (the checklist was replaced), and nobody may edit a closed version.
        if ($this->addingToStageId !== null && ! ($this->canEdit && $this->checklist?->stages->contains('id', $this->addingToStageId))) {
            $this->cancelAddItem();
        }
        if (! $this->assignable->contains('id', $this->assignPartyId)) {
            $this->assignPartyId = $this->assignable->first()?->id;
        }

        return $this->view();
    }
}; ?>

<div data-test="checklist">
    @if ($this->checklist === null)
        <flux:card><flux:text>{{ __('This checklist no longer exists; it may have been replaced.') }}</flux:text></flux:card>
    @else
        @php($items = $this->checklist->stages->flatMap->items)
        @php($done = $items->whereNotNull('checked_at')->count())
        <flux:card class="space-y-5">
            <div class="space-y-2">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <flux:heading size="lg" level="2" class="wrap-anywhere" data-test="checklist-name">{{ $this->checklist->name }}</flux:heading>
                        <flux:text size="sm">{{ __(':done of :total done', ['done' => $done, 'total' => $items->count()]) }}</flux:text>
                    </div>
                    <div class="flex flex-col items-end gap-2">
                        <flux:radio.group wire:model.live="itemFilter" variant="segmented" size="sm" data-test="item-filter">
                            <flux:radio value="all" :label="__('All')" />
                            <flux:radio value="open" :label="__('Not ticked')" />
                        </flux:radio.group>
                        <flux:text size="sm">
                            {{ __('Written by :who, :when', ['who' => $this->checklist->createdByUser?->name ?? $this->checklist->createdByParty->name, 'when' => $this->checklist->created_at?->diffForHumans()]) }}
                        </flux:text>
                    </div>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-700" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $items->count() }}" aria-valuenow="{{ $done }}">
                    <div class="h-full rounded-full bg-green-500" style="width: {{ $items->isEmpty() ? 0 : round(100 * $done / $items->count()) }}%"></div>
                </div>
                @unless ($contract->isOpen())
                    <flux:text size="sm">{{ __('The contract is marked :status, so the checklist is read-only.', ['status' => strtolower(__($contract->status->label()))]) }}</flux:text>
                @endunless
                @if ($this->checklist->assignedParties->isNotEmpty() || $this->canAssign)
                    <div class="flex flex-wrap items-center gap-2" data-test="checklist-assignees">
                        <flux:text size="sm">{{ __('Assigned:') }}</flux:text>
                        @forelse ($this->checklist->assignedParties as $assignee)
                            <flux:badge size="sm" wire:key="assignee-{{ $assignee->id }}">
                                {{ $assignee->name }}
                                @if ($this->canAssign)
                                    <flux:badge.close wire:click="unassign('{{ $assignee->id }}')" :aria-label="__('Remove :party', ['party' => $assignee->name])" />
                                @endif
                            </flux:badge>
                        @empty
                            <flux:text size="sm">{{ __('nobody yet; assigned parties tick items whatever their role') }}</flux:text>
                        @endforelse
                        @if ($this->canAssign && $this->assignable->isNotEmpty())
                            <form wire:submit="assign" class="flex items-center gap-2">
                                <flux:select wire:model="assignPartyId" size="sm" class="max-w-56" :aria-label="__('Party to assign')">
                                    @foreach ($this->assignable as $party)
                                        <flux:select.option :value="$party->id">{{ $party->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:button type="submit" size="sm">{{ __('Assign') }}</flux:button>
                            </form>
                        @endif
                    </div>
                @endif
            </div>

            @if ($itemFilter === 'open' && $done === $items->count())
                <flux:text size="sm" data-test="item-filter-empty">{{ __('Every item is ticked.') }}</flux:text>
            @endif

            @foreach ($this->checklist->stages as $stage)
                {{-- Moving up and down is off while filtering, since positions count the hidden items too. --}}
                @php($filtering = $itemFilter === 'open')
                @php($shownItems = $filtering ? $stage->items->whereNull('checked_at')->values() : $stage->items)
                @php($stageDone = $stage->items->whereNotNull('checked_at')->count())
                <section class="space-y-1" wire:key="stage-{{ $stage->id }}" data-test="checklist-stage">
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-200 pb-1 dark:border-zinc-700">
                        <h3 class="font-semibold">{{ $stage->heading() }}</h3>
                        <flux:badge size="sm" :color="$stage->items->isNotEmpty() && $stageDone === $stage->items->count() ? 'green' : 'zinc'">{{ $stageDone }}/{{ $stage->items->count() }}</flux:badge>
                    </div>

                    @forelse ($shownItems as $item)
                        <div class="flex items-start gap-3 py-1.5" wire:key="item-{{ $item->id }}" data-test="checklist-item">
                            <input type="checkbox" class="mt-1 size-4 shrink-0 accent-green-600 disabled:opacity-60"
                                   @checked($item->isChecked()) @disabled(! $this->canTick)
                                   wire:click="toggle('{{ $item->id }}')" aria-label="{{ $item->title }}">
                            @if ($editingItemId === $item->id)
                                <form wire:submit="saveItem" class="min-w-0 flex-1 space-y-3" data-test="item-editor">
                                    <flux:input wire:model="itemTitle" :label="__('Title')" />
                                    <flux:textarea wire:model="itemNotes" :label="__('Notes (Markdown, optional)')" rows="3" />
                                    <flux:select wire:model="itemStepId" :label="__('Contract step')">
                                        <flux:select.option value="">{{ __('No step') }}</flux:select.option>
                                        @foreach ($this->stepOptions as $option)
                                            <flux:select.option :value="$option->id">{{ $option->currentRevision?->title }}</flux:select.option>
                                        @endforeach
                                        @if ($item->step && ! $this->stepOptions->contains('id', $item->step_id))
                                            <flux:select.option :value="$item->step_id">{{ __(':title (archived)', ['title' => $item->step->currentRevision?->title]) }}</flux:select.option>
                                        @endif
                                    </flux:select>
                                    <div class="flex gap-2">
                                        <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
                                        <flux:button size="sm" variant="ghost" wire:click="cancelEditItem">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </form>
                            @else
                                <div class="min-w-0 flex-1 space-y-1">
                                    <div @class(['text-sm wrap-anywhere', 'text-zinc-500 line-through' => $item->isChecked()])>{{ $item->title }}</div>
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
                                @if ($this->canEdit)
                                    <flux:dropdown align="end">
                                        <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Item actions')" data-test="item-actions" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" wire:click="editItem('{{ $item->id }}')">{{ __('Edit') }}</flux:menu.item>
                                            @unless ($filtering)
                                                <flux:menu.item icon="chevron-up" wire:click="moveItem('{{ $item->id }}', -1)" :disabled="$loop->first">{{ __('Move up') }}</flux:menu.item>
                                                <flux:menu.item icon="chevron-down" wire:click="moveItem('{{ $item->id }}', 1)" :disabled="$loop->last">{{ __('Move down') }}</flux:menu.item>
                                            @endunless
                                            @if ($this->checklist->stages->count() > 1)
                                                <flux:menu.submenu icon="arrow-right" :heading="__('Move to stage')">
                                                    @foreach ($this->checklist->stages as $otherStage)
                                                        @continue($otherStage->id === $stage->id)
                                                        <flux:menu.item wire:click="moveItemTo('{{ $item->id }}', '{{ $otherStage->id }}')">{{ $otherStage->heading() }}</flux:menu.item>
                                                    @endforeach
                                                </flux:menu.submenu>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="deleteItem('{{ $item->id }}')"
                                                            wire:confirm="{{ $item->isChecked() ? __('Delete this ticked item? This cannot be undone.') : __('Delete this item? This cannot be undone.') }}">{{ __('Delete') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            @endif
                        </div>
                    @empty
                        <flux:text size="sm" class="py-1">{{ $filtering && $stage->items->isNotEmpty() ? __('All ticked.') : __('No items.') }}</flux:text>
                    @endforelse

                    @if ($this->canEdit)
                        @if ($addingToStageId === $stage->id)
                            <form wire:submit="addItem" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="item-adder">
                                <flux:input wire:model="newItemTitle" :label="__('New item')" autofocus />
                                <flux:textarea wire:model="newItemNotes" :label="__('Notes (Markdown, optional)')" rows="2" />
                                <flux:select wire:model="newItemStepId" :label="__('Contract step')">
                                    <flux:select.option value="">{{ __('No step') }}</flux:select.option>
                                    @foreach ($this->stepOptions as $option)
                                        <flux:select.option :value="$option->id">{{ $option->currentRevision?->title }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <div class="flex gap-2">
                                    <flux:button size="sm" type="submit" variant="primary">{{ __('Add item') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" wire:click="cancelAddItem">{{ __('Done') }}</flux:button>
                                </div>
                            </form>
                        @else
                            <flux:button size="xs" variant="subtle" icon="plus" wire:click="startAddingItem('{{ $stage->id }}')" data-test="add-item">{{ __('Add item') }}</flux:button>
                        @endif
                    @endif
                </section>
            @endforeach
        </flux:card>
    @endif
</div>
