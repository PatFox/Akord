<?php

use App\Actions\Approvals\ApproveStep;
use App\Actions\Approvals\WithdrawApproval;
use App\Actions\Comments\AddComment;
use App\Actions\Comments\ResolveComment;
use App\Actions\Contracts\ContractReadiness;
use App\Actions\Steps\ArchiveStep;
use App\Actions\Steps\ReviseStep;
use App\Enums\CommentKind;
use App\Enums\ContractAbility;
use App\Livewire\Concerns\ActsOnContract;
use App\Livewire\Concerns\RunsActions;
use App\Models\Approval;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Step;
use App\Models\StepRevision;
use App\Support\RevisionDiff;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * One step on the contract page: its row in the approval matrix, and (expanded) its text, editor, comments and
 * history. Expanding and collapsing happen in the browser; the contract page tells this component to refresh
 * when an activity concerns the step ("step-refresh.{id}") or every step ("steps-refresh").
 */
new class extends Component {
    use ActsOnContract, RunsActions;

    public Contract $contract;

    public Step $step;

    public bool $editing = false;

    /** The revision the editor started from; sent with the save to detect conflicts. */
    public int $baseRevision = 0;

    public string $title = '';

    public string $description = '';

    public string $changeNote = '';

    public bool $previewing = false;

    /** Set when someone else saved while this person was editing. */
    public ?int $conflictWith = null;

    public string $commentKind = 'general';

    public string $commentBody = '';

    public ?string $replyTo = null;

    public string $replyBody = '';

    public bool $showHistory = false;

    public ?int $compareFrom = null;

    public ?int $compareTo = null;

    public function mount(Contract $contract, Step $step): void
    {
        Gate::authorize('view', $contract);
        $this->contract = $contract;
        $this->step = $contract->findStep($step->id);
        $this->initialiseActingParty();
    }

    #[Computed]
    public function current(): StepRevision
    {
        return $this->step->currentRevision()->with(['authorParty', 'authorUser'])->firstOrFail();
    }

    /**
     * Required parties, in the contract page's column order.
     *
     * @return Collection<int, Party>
     */
    #[Computed]
    public function requiredParties(): Collection
    {
        return $this->contract->requiredApprovers()->orderBy('name')->orderBy('parties.id')->get();
    }

    /**
     * @return array<string, true> "stepId:partyId" for approvals that count
     */
    #[Computed]
    public function approved(): array
    {
        return ContractReadiness::countingApprovals([$this->step->id], $this->requiredParties->pluck('id')->all());
    }

    /**
     * The acting party's approval: 'counts', 'cancelled' (it approved content that has since changed) or 'none'.
     */
    #[Computed]
    public function myApproval(): string
    {
        $party = $this->actor()->party;
        if ($party === null) {
            return 'none';
        }

        $active = Approval::query()->active()->with('revision')->where('step_id', $this->step->id)->where('party_id', $party->id)->get();

        return match (true) {
            $active->contains(fn (Approval $a) => $a->revision->content_hash === $this->current->content_hash) => 'counts',
            $active->isNotEmpty() => 'cancelled',
            default => 'none',
        };
    }

    /**
     * The required party whose tick this person may click, if any.
     */
    #[Computed]
    public function approvingPartyId(): ?string
    {
        $party = $this->actor()->party;

        return $party !== null && $this->canApprove && $this->requiredParties->contains('id', $party->id) ? $party->id : null;
    }

    /**
     * @return Collection<int, Comment>
     */
    #[Computed]
    public function threads(): Collection
    {
        return $this->step->comments()
            ->whereNull('parent_id')
            ->with(['authorParty', 'authorUser', 'revision', 'replies' => fn ($replies) => $replies->with(['authorParty', 'authorUser'])->oldest()])
            ->oldest()
            ->get();
    }

    #[Computed]
    public function commentCount(): int
    {
        return $this->step->comments()->count();
    }

    /**
     * @return Collection<int, StepRevision>
     */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->step->revisions()->with(['authorParty', 'authorUser'])->get();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return ! $this->contract->isLocked() && ! $this->step->isArchived() && $this->allows(ContractAbility::Edit);
    }

    #[Computed]
    public function canComment(): bool
    {
        return ! $this->contract->isLocked() && ! $this->step->isArchived() && $this->allows(ContractAbility::Comment);
    }

    #[Computed]
    public function canApprove(): bool
    {
        return ! $this->contract->isLocked() && ! $this->step->isArchived() && $this->allows(ContractAbility::Approve);
    }

    #[Computed]
    public function diffHtml(): ?string
    {
        if ($this->compareFrom === null || $this->compareTo === null || $this->compareFrom === $this->compareTo) {
            return null;
        }

        $from = $this->revisions->firstWhere('number', $this->compareFrom);
        $to = $this->revisions->firstWhere('number', $this->compareTo);

        return $from && $to ? RevisionDiff::html($from, $to) : null;
    }

    /**
     * Someone changed this step (or the whole contract). If they saved a revision while this person is editing,
     * the conflict banner appears before they try to save.
     */
    #[On('step-refresh.{step.id}')]
    #[On('steps-refresh')]
    public function refreshStep(): void
    {
        $this->step->refresh();
        unset($this->current);

        if ($this->editing && $this->conflictWith === null && $this->current->number !== $this->baseRevision) {
            $this->conflictWith = $this->current->number;
        }
    }

    public function edit(): void
    {
        $this->editing = true;
        $this->previewing = false;
        $this->conflictWith = null;
        $this->baseRevision = $this->current->number;
        $this->title = $this->current->title;
        $this->description = $this->current->description_md;
        $this->changeNote = '';
    }

    public function cancelEdit(): void
    {
        $this->reset('editing', 'previewing', 'conflictWith', 'title', 'description', 'changeNote');
    }

    /**
     * After a conflict: keep this person's text, but apply it on top of the latest revision.
     */
    public function rebase(): void
    {
        $this->baseRevision = $this->current->number;
        $this->conflictWith = null;
    }

    public function save(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:20000'],
            'changeNote' => ['nullable', 'string', 'max:500'],
        ]);

        // A conflict (409) is reported through refused(), which keeps the draft and shows the banner.
        $revision = $this->attempt(fn () => app(ReviseStep::class)->handle(
            $this->actor(), $this->step, $this->baseRevision, $this->title, $this->description, $this->changeNote ?: null,
        ));

        if ($revision !== null) {
            Flux::toast(variant: 'success', text: __('Saved as revision :n.', ['n' => $revision->number]));
            $this->cancelEdit();
            $this->compareTo = $revision->number;
            $this->compareFrom = max(1, $revision->number - 1);
            $this->changed();
        }
    }

    protected function refused(string $message, int $status): void
    {
        if ($status === 409 && $this->editing) {
            $this->step->refresh();
            unset($this->current);
            $this->conflictWith = $this->current->number;

            return;
        }

        Flux::toast(variant: 'danger', text: $message);
    }

    /**
     * Approves the revision on screen; a change since then is a conflict (409).
     */
    public function approve(int $reviewedRevision): void
    {
        if ($this->attempt(fn () => app(ApproveStep::class)->handle($this->actor(), $this->step, $reviewedRevision)) !== null) {
            $this->changed();
        }
    }

    public function withdrawApproval(): void
    {
        if ($this->attempt(fn () => app(WithdrawApproval::class)->handle($this->actor(), $this->step)) !== null) {
            $this->changed();
        }
    }

    public function archive(): void
    {
        if ($this->attempt(fn () => app(ArchiveStep::class)->handle($this->actor(), $this->step), __('Step archived.')) !== null) {
            $this->changed();
        }
    }

    public function addComment(): void
    {
        $this->validate([
            'commentKind' => ['required', Rule::enum(CommentKind::class)],
            'commentBody' => ['required', 'string', 'max:10000'],
        ]);

        if ($this->attempt(fn () => app(AddComment::class)->handle($this->actor(), $this->step, CommentKind::from($this->commentKind), $this->commentBody)) !== null) {
            $this->reset('commentBody', 'commentKind');
        }
    }

    public function reply(): void
    {
        $this->validate(['replyBody' => ['required', 'string', 'max:10000']]);

        $parent = $this->step->comments()->findOrFail($this->replyTo);

        if ($this->attempt(fn () => app(AddComment::class)->handle($this->actor(), $this->step, CommentKind::General, $this->replyBody, $parent)) !== null) {
            $this->reset('replyTo', 'replyBody');
        }
    }

    public function resolve(string $commentId): void
    {
        $comment = $this->step->comments()->findOrFail($commentId);
        $this->attempt(fn () => app(ResolveComment::class)->handle($this->actor(), $comment));
    }

    public function toggleHistory(): void
    {
        $this->showHistory = ! $this->showHistory;
        $current = $this->current->number;
        $this->compareTo ??= $current;
        $this->compareFrom ??= max(1, $current - 1);
    }

    /**
     * Tells the contract page that readiness may have changed (its sign-off panel and stage counts).
     */
    private function changed(): void
    {
        $this->dispatch('step-updated');
    }

    public function render()
    {
        // Actions may have changed the step or contract during this request.
        $this->step->refresh();
        unset($this->current, $this->requiredParties, $this->approved, $this->myApproval, $this->approvingPartyId, $this->threads, $this->commentCount, $this->revisions, $this->canEdit, $this->canComment, $this->canApprove, $this->diffHtml);

        return $this->view();
    }
}; ?>

<div id="step-{{ $step->id }}" class="min-w-0 flex-1" data-test="step"
     x-data="{
         open: false,
         comments: false,
         reveal() {
             if (location.hash !== '#' + this.$el.id) return;
             this.open = true;
             this.comments = true;
             this.$el.closest('[data-phase]')?.dispatchEvent(new CustomEvent('reveal-phase'));
             this.$nextTick(() => this.$el.scrollIntoView({ block: 'start' }));
         },
     }"
     x-init="reveal()" x-on:hashchange.window="reveal()"
     x-on:steps-toggle.window="if ($event.detail.phase === null || $el.closest('[data-phase]')?.dataset.phase === $event.detail.phase) open = $event.detail.open">
    {{-- The row: title, revision, comments and one tick per required party, aligned with the column header. --}}
    <div class="flex items-center gap-2 py-2">
        <button type="button" class="flex min-w-0 flex-1 items-center gap-1.5 text-start" x-on:click="open = ! open" x-bind:aria-expanded="open">
            <flux:icon.chevron-right variant="micro" class="shrink-0 text-zinc-400 transition-transform" x-bind:class="open && 'rotate-90'" />
            <span class="truncate font-medium">{{ $this->current->title }}</span>
            @if ($step->isArchived())
                <flux:badge size="sm" color="zinc" icon="archive-box">{{ __('Archived') }}</flux:badge>
            @endif
        </button>
        <span class="w-10 shrink-0 text-center text-xs text-zinc-500" title="{{ __('Revision :n', ['n' => $this->current->number]) }}">r{{ $this->current->number }}</span>
        <button type="button" class="flex w-12 shrink-0 items-center justify-center gap-1 text-xs text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
                x-on:click="open = true; comments = ! comments" title="{{ trans_choice(':count comment|:count comments', $this->commentCount) }}" data-test="comment-count">
            <flux:icon.chat-bubble-left variant="micro" />{{ $this->commentCount }}
        </button>
        @foreach ($this->requiredParties as $party)
            @php($approved = isset($this->approved[$step->id.':'.$party->id]))
            <span class="w-24 shrink-0 text-center" wire:key="tick-{{ $party->id }}">
                @if ($party->id === $this->approvingPartyId)
                    @if ($approved)
                        <button type="button" wire:click="withdrawApproval" title="{{ __('You approved revision :n. Click to withdraw.', ['n' => $this->current->number]) }}" data-test="my-tick">
                            <flux:icon.check-circle variant="solid" class="inline size-6 text-green-500 hover:text-green-600" />
                        </button>
                    @elseif ($this->myApproval === 'cancelled')
                        <button type="button" wire:click="approve({{ $this->current->number }})" title="{{ __('Your approval was for an earlier revision. Click to approve revision :n.', ['n' => $this->current->number]) }}" data-test="my-tick">
                            <flux:icon.exclamation-circle variant="solid" class="inline size-6 text-amber-500 hover:text-green-500" />
                        </button>
                    @else
                        <button type="button" wire:click="approve({{ $this->current->number }})" title="{{ __('Click to approve revision :n.', ['n' => $this->current->number]) }}" data-test="my-tick">
                            <flux:icon.check-circle variant="outline" class="inline size-6 text-zinc-400 hover:text-green-500" />
                        </button>
                    @endif
                @elseif ($approved)
                    <flux:icon.check-circle variant="mini" class="inline text-green-500" title="{{ __(':party approved', ['party' => $party->name]) }}" />
                @else
                    <flux:icon.minus-circle variant="mini" class="inline text-zinc-300 dark:text-zinc-600" title="{{ __(':party has not approved', ['party' => $party->name]) }}" />
                @endif
            </span>
        @endforeach
        <span class="w-8 shrink-0 text-end">
            <flux:dropdown align="end">
                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Step actions')" />
                <flux:menu>
                    @if ($this->canEdit)
                        <flux:menu.item icon="pencil" wire:click="edit" x-on:click="open = true">{{ __('Edit') }}</flux:menu.item>
                    @endif
                    <flux:menu.item icon="clock" wire:click="toggleHistory" x-on:click="open = true">{{ $showHistory ? __('Hide history') : __('History') }}</flux:menu.item>
                    @if ($this->canEdit)
                        <flux:menu.separator />
                        <flux:menu.item icon="archive-box" variant="danger" wire:click="archive" wire:confirm="{{ __('Archive this step? It disappears from the contract but stays in history.') }}">{{ __('Archive') }}</flux:menu.item>
                    @endif
                </flux:menu>
            </flux:dropdown>
        </span>
    </div>

    {{-- Expanded: text or editor, comments, history. --}}
    <div x-show="open" x-cloak class="space-y-4 pb-4 ps-5">
        @if ($this->myApproval === 'cancelled' && $this->approvingPartyId !== null)
            <flux:text size="sm" class="text-amber-600 dark:text-amber-400">
                {{ __('The step changed after your party approved it. Review revision :n and approve again if you agree.', ['n' => $this->current->number]) }}
            </flux:text>
        @endif

        @if ($editing)
            <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                @if ($conflictWith)
                    <flux:callout icon="exclamation-triangle" color="red" :heading="__('This step changed while you were editing')" data-test="edit-conflict">
                        <flux:callout.text>
                            {{ __('Someone saved revision :n after you started. Your text is kept below. Compare it with the latest version, then save again to apply your edit on top of it.', ['n' => $conflictWith]) }}
                        </flux:callout.text>
                        <x-slot name="actions">
                            <flux:button size="sm" wire:click="rebase">{{ __('Edit on top of revision :n', ['n' => $conflictWith]) }}</flux:button>
                        </x-slot>
                    </flux:callout>
                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <strong class="text-sm">{{ __('Latest') }}: {{ $this->current->title }}</strong>
                        <x-markdown :source="$this->current->description_md" />
                    </div>
                @endif

                <form wire:submit="save" class="space-y-4">
                    <flux:input wire:model="title" :label="__('Title')" />
                    <div class="space-y-2">
                        <div class="flex items-center gap-2">
                            <flux:button size="xs" :variant="$previewing ? 'ghost' : 'filled'" wire:click="$set('previewing', false)">{{ __('Write') }}</flux:button>
                            <flux:button size="xs" :variant="$previewing ? 'filled' : 'ghost'" wire:click="$set('previewing', true)">{{ __('Preview') }}</flux:button>
                        </div>
                        @if ($previewing)
                            <x-markdown :source="$description" class="min-h-32 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" data-test="markdown-preview" />
                        @else
                            <flux:textarea wire:model="description" :label="__('Description (Markdown)')" rows="10" />
                        @endif
                    </div>
                    <flux:input wire:model="changeNote" :label="__('Change note (optional)')" />
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary" :disabled="$conflictWith !== null">{{ __('Save revision') }}</flux:button>
                        <flux:button variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                    </div>
                </form>
            </div>
        @else
            <div class="flex items-start gap-3">
                <x-markdown :source="$this->current->description_md" class="min-w-0 flex-1" data-test="step-description" />
                @if ($this->canEdit)
                    <flux:button size="xs" variant="ghost" icon="pencil" wire:click="edit">{{ __('Edit') }}</flux:button>
                @endif
            </div>
            <flux:text size="xs">
                {{ __('Revision :n', ['n' => $this->current->number]) }} ·
                {{ $this->current->authorUser?->name ?? $this->current->authorParty?->name }} ·
                {{ $this->current->created_at?->diffForHumans() }}
            </flux:text>
        @endif

        {{-- Comments, collapsed by default. --}}
        <div class="space-y-3">
            <button type="button" class="flex items-center gap-1 text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white" x-on:click="comments = ! comments">
                <flux:icon.chevron-right variant="micro" class="transition-transform" x-bind:class="comments && 'rotate-90'" />
                {{ trans_choice(':count comment|:count comments', $this->commentCount) }}
            </button>

            <div x-show="comments" x-cloak class="space-y-3">
                @foreach ($this->threads as $comment)
                    <div class="space-y-2 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700" wire:key="comment-{{ $comment->id }}">
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            @if ($comment->authorUser)
                                <strong>{{ $comment->authorUser->name }}</strong>
                                <flux:text size="sm">{{ $comment->authorParty->name }} · {{ __('on revision :n', ['n' => $comment->revision->number]) }} · {{ $comment->created_at?->diffForHumans() }}</flux:text>
                            @else
                                <strong>{{ $comment->authorParty->name }}</strong>
                                <flux:badge size="sm" color="purple">{{ __('Agent') }}</flux:badge>
                                <flux:text size="sm">{{ __('on revision :n', ['n' => $comment->revision->number]) }} · {{ $comment->created_at?->diffForHumans() }}</flux:text>
                            @endif
                            <flux:badge size="sm" :color="match ($comment->kind->value) { 'question' => 'sky', 'suggestion' => 'amber', default => 'zinc' }">{{ ucfirst($comment->kind->value) }}</flux:badge>
                            @if ($comment->resolved_at)
                                <flux:badge size="sm" color="green" icon="check">{{ __('Resolved') }}</flux:badge>
                            @endif
                        </div>
                        <x-markdown :source="$comment->body_md" />

                        @foreach ($comment->replies as $reply)
                            <div class="ms-4 border-s-2 border-zinc-200 ps-3 dark:border-zinc-700" wire:key="reply-{{ $reply->id }}">
                                <flux:text size="sm"><strong>{{ $reply->authorUser->name ?? $reply->authorParty->name }}</strong>@unless ($reply->authorUser) ({{ __('agent') }})@endunless · {{ $reply->created_at?->diffForHumans() }}</flux:text>
                                <x-markdown :source="$reply->body_md" />
                            </div>
                        @endforeach

                        @if ($this->canComment)
                            @if ($replyTo === $comment->id)
                                <form wire:submit="reply" class="ms-4 space-y-2">
                                    <flux:textarea wire:model="replyBody" rows="2" :placeholder="__('Reply…')" />
                                    <div class="flex gap-2">
                                        <flux:button size="xs" type="submit" variant="primary">{{ __('Reply') }}</flux:button>
                                        <flux:button size="xs" variant="ghost" wire:click="$set('replyTo', null)">{{ __('Cancel') }}</flux:button>
                                    </div>
                                </form>
                            @else
                                <div class="flex gap-2">
                                    <flux:button size="xs" variant="ghost" wire:click="$set('replyTo', '{{ $comment->id }}')">{{ __('Reply') }}</flux:button>
                                    @unless ($comment->resolved_at)
                                        <flux:button size="xs" variant="ghost" wire:click="resolve('{{ $comment->id }}')">{{ __('Resolve') }}</flux:button>
                                    @endunless
                                </div>
                            @endif
                        @endif
                    </div>
                @endforeach

                @if ($this->canComment)
                    <form wire:submit="addComment" class="space-y-2">
                        <flux:select wire:model="commentKind" size="sm" class="max-w-40">
                            @foreach (\App\Enums\CommentKind::cases() as $kind)
                                <flux:select.option :value="$kind->value">{{ ucfirst($kind->value) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:textarea wire:model="commentBody" rows="3" :placeholder="__('Ask a question, suggest a change… (Markdown)')" />
                        <flux:button size="sm" type="submit">{{ __('Comment') }}</flux:button>
                    </form>
                @elseif ($this->threads->isEmpty())
                    <flux:text size="sm">{{ __('No comments yet.') }}</flux:text>
                @endif
            </div>
        </div>

        {{-- History, on request. --}}
        @if ($showHistory)
            <div class="space-y-3" data-test="step-history">
                <flux:heading size="sm">{{ __('History') }}</flux:heading>
                <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
                    @foreach ($this->revisions->reverse() as $revision)
                        @php($previous = $this->revisions->firstWhere('number', $revision->number - 1))
                        <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="rev-{{ $revision->id }}">
                            <span>
                                <strong>{{ __('Revision :n', ['n' => $revision->number]) }}</strong>
                                · @if ($revision->authorUser){{ $revision->authorUser->name }} ({{ $revision->authorParty->name }})@else{{ $revision->authorParty->name }} ({{ __('agent') }})@endif
                                · {{ $revision->created_at?->toDayDateTimeString() }}
                                @if ($revision->change_note) · <em>{{ $revision->change_note }}</em>@endif
                            </span>
                            @if ($previous && $previous->content_hash === $revision->content_hash)
                                <flux:badge size="sm" color="zinc">{{ __('Spacing only') }}</flux:badge>
                            @endif
                        </div>
                    @endforeach
                </div>

                @if ($this->revisions->count() > 1)
                    <div class="flex flex-wrap items-end gap-3">
                        <flux:select wire:model.live="compareFrom" size="sm" :label="__('Compare')" class="max-w-40">
                            @foreach ($this->revisions as $revision)
                                <flux:select.option :value="$revision->number">{{ __('Revision :n', ['n' => $revision->number]) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model.live="compareTo" size="sm" :label="__('with')" class="max-w-40">
                            @foreach ($this->revisions as $revision)
                                <flux:select.option :value="$revision->number">{{ __('Revision :n', ['n' => $revision->number]) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="overflow-x-auto text-sm" data-test="revision-diff">
                        @if ($this->diffHtml === null)
                            <flux:text size="sm">{{ __('Choose two different revisions to compare.') }}</flux:text>
                        @elseif ($this->diffHtml === '')
                            <flux:text size="sm">{{ __('No differences.') }}</flux:text>
                        @else
                            {!! $this->diffHtml !!}
                        @endif
                    </div>
                @endif
            </div>
        @endif
    </div>
</div>
