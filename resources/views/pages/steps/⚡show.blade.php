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

    public ?int $replyTo = null;

    public string $replyBody = '';

    public ?int $compareFrom = null;

    public ?int $compareTo = null;

    public function mount(Contract $contract, Step $step): void
    {
        Gate::authorize('view', $contract);
        $this->contract = $contract;
        $this->step = $step;
        $this->initialiseActingParty();

        $current = $step->currentRevision?->number ?? 1;
        $this->compareTo = $current;
        $this->compareFrom = max(1, $current - 1);
    }

    #[Computed]
    public function current(): StepRevision
    {
        return $this->step->currentRevision()->with(['authorParty', 'authorUser'])->firstOrFail();
    }

    /**
     * @return Collection<int, StepRevision>
     */
    #[Computed]
    public function revisions(): Collection
    {
        return $this->step->revisions()->with(['authorParty', 'authorUser'])->get();
    }

    /**
     * @return Collection<int, array{party: Party, approved: bool}>
     */
    #[Computed]
    public function approvals(): Collection
    {
        $report = ContractReadiness::report($this->contract);
        $outstanding = collect($report->outstanding)->where('step_id', $this->step->id)->pluck('party_id')->all();

        return Party::query()->whereIn('id', $report->requiredPartyIds)->orderBy('name')->get()
            ->map(fn (Party $party) => ['party' => $party, 'approved' => ! in_array($party->id, $outstanding, true)]);
    }

    /**
     * The acting party's approval state for this step: 'counts', 'cancelled' (it approved
     * content that has since changed) or 'none'.
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
    public function canEdit(): bool
    {
        return ! $this->contract->isAgreed() && ! $this->step->isArchived() && $this->allows(ContractAbility::Edit);
    }

    #[Computed]
    public function canComment(): bool
    {
        return ! $this->contract->isAgreed() && ! $this->step->isArchived() && $this->allows(ContractAbility::Comment);
    }

    #[Computed]
    public function canApprove(): bool
    {
        return ! $this->contract->isAgreed() && ! $this->step->isArchived() && $this->allows(ContractAbility::Approve);
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
     * Live update (Phase 5): the page re-renders; if someone saved a revision while this
     * person is editing, the conflict banner appears before they try to save.
     */
    #[On('echo-private:contract.{contract.id},.activity')]
    public function onActivity(array $event): void
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

    public function approve(int $reviewedRevision): void
    {
        $this->attempt(fn () => app(ApproveStep::class)->handle($this->actor(), $this->step, $reviewedRevision), __('Approved.'));
    }

    public function withdrawApproval(): void
    {
        $this->attempt(fn () => app(WithdrawApproval::class)->handle($this->actor(), $this->step), __('Approval withdrawn.'));
    }

    public function archive(): void
    {
        $this->attempt(fn () => app(ArchiveStep::class)->handle($this->actor(), $this->step), __('Step archived.'));
    }

    public function addComment(): void
    {
        $this->validate([
            'commentKind' => ['required', Rule::enum(CommentKind::class)],
            'commentBody' => ['required', 'string', 'max:10000'],
        ]);

        $added = $this->attempt(fn () => app(AddComment::class)->handle($this->actor(), $this->step, CommentKind::from($this->commentKind), $this->commentBody));

        if ($added !== null) {
            $this->reset('commentBody', 'commentKind');
        }
    }

    public function reply(): void
    {
        $this->validate(['replyBody' => ['required', 'string', 'max:10000']]);

        $parent = $this->step->comments()->findOrFail($this->replyTo);
        $added = $this->attempt(fn () => app(AddComment::class)->handle($this->actor(), $this->step, CommentKind::General, $this->replyBody, $parent));

        if ($added !== null) {
            $this->reset('replyTo', 'replyBody');
        }
    }

    public function resolve(int $commentId): void
    {
        $comment = $this->step->comments()->findOrFail($commentId);
        $this->attempt(fn () => app(ResolveComment::class)->handle($this->actor(), $comment));
    }

    public function render()
    {
        // Actions may have changed the step or contract during this request.
        $this->contract->refresh();
        $this->step->refresh();
        unset($this->current, $this->revisions, $this->approvals, $this->myApproval, $this->threads, $this->canEdit, $this->canComment, $this->canApprove, $this->diffHtml);

        return $this->view()->title($this->current->title);
    }
}; ?>

<section class="w-full max-w-5xl space-y-6">
    <style>{!! \App\Support\RevisionDiff::styles() !!}</style>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-contract-breadcrumbs :contract="$contract" :current="$this->current->title" />
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $this->current->title }}</flux:heading>
                <flux:badge>{{ __('Revision :n', ['n' => $this->current->number]) }}</flux:badge>
                <flux:text size="sm">{{ $step->phase->title }}</flux:text>
                @if ($step->isArchived())
                    <flux:badge color="zinc" icon="archive-box">{{ __('Archived') }}</flux:badge>
                @endif
            </div>
        </div>
        <x-acting-as :options="$this->actingOptions" />
    </div>

    @if ($this->myApproval === 'cancelled')
        <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Your approval was cancelled')">
            <flux:callout.text>{{ __('The step changed after your party approved it. Review revision :n and approve again if you agree.', ['n' => $this->current->number]) }}</flux:callout.text>
        </flux:callout>
    @endif

    {{-- Approvals --}}
    <flux:card class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap gap-2">
            @forelse ($this->approvals as $item)
                <flux:badge :color="$item['approved'] ? 'green' : 'zinc'" :icon="$item['approved'] ? 'check-circle' : 'clock'" wire:key="appr-{{ $item['party']->id }}">
                    {{ $item['party']->name }}
                </flux:badge>
            @empty
                <flux:text size="sm">{{ __('No required approvers yet.') }}</flux:text>
            @endforelse
        </div>
        @if ($this->canApprove)
            @if ($this->myApproval === 'counts')
                <div class="flex items-center gap-2">
                    <flux:badge color="green" icon="check">{{ __('You approved') }}</flux:badge>
                    <flux:button size="sm" variant="ghost" wire:click="withdrawApproval">{{ __('Withdraw') }}</flux:button>
                </div>
            @else
                <flux:button variant="primary" wire:click="approve({{ $this->current->number }})" data-test="approve-button">
                    {{ __('Approve revision :n', ['n' => $this->current->number]) }}
                </flux:button>
            @endif
        @endif
    </flux:card>

    {{-- Content / editor --}}
    @if ($editing)
        <flux:card class="space-y-4">
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
                        <flux:textarea wire:model="description" :label="__('Description (Markdown)')" rows="12" />
                    @endif
                </div>
                <flux:input wire:model="changeNote" :label="__('Change note (optional)')" />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary" :disabled="$conflictWith !== null">{{ __('Save revision') }}</flux:button>
                    <flux:button variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                </div>
            </form>
        </flux:card>
    @else
        <flux:card class="space-y-3">
            <x-markdown :source="$this->current->description_md" data-test="step-description" />
            @if ($this->canEdit)
                <div class="flex gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                    <flux:button size="sm" icon="pencil" wire:click="edit">{{ __('Edit') }}</flux:button>
                    <flux:button size="sm" variant="ghost" icon="archive-box" wire:click="archive" wire:confirm="{{ __('Archive this step? It disappears from the contract but stays in history.') }}">{{ __('Archive') }}</flux:button>
                </div>
            @endif
        </flux:card>
    @endif

    {{-- Comments --}}
    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('Discussion') }}</flux:heading>

        @forelse ($this->threads as $comment)
            <flux:card class="space-y-2" wire:key="comment-{{ $comment->id }}">
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <strong>{{ $comment->authorUser?->name ?? $comment->authorParty->name }}</strong>
                    <flux:text size="sm">{{ $comment->authorParty->name }} · {{ __('on revision :n', ['n' => $comment->revision->number]) }} · {{ $comment->created_at?->diffForHumans() }}</flux:text>
                    <flux:badge size="sm" :color="match ($comment->kind->value) { 'question' => 'sky', 'suggestion' => 'amber', default => 'zinc' }">{{ ucfirst($comment->kind->value) }}</flux:badge>
                    @if ($comment->resolved_at)
                        <flux:badge size="sm" color="green" icon="check">{{ __('Resolved') }}</flux:badge>
                    @endif
                </div>
                <x-markdown :source="$comment->body_md" />

                @foreach ($comment->replies as $reply)
                    <div class="ms-4 border-s-2 border-zinc-200 ps-3 dark:border-zinc-700" wire:key="reply-{{ $reply->id }}">
                        <flux:text size="sm"><strong>{{ $reply->authorUser?->name ?? $reply->authorParty->name }}</strong> · {{ $reply->created_at?->diffForHumans() }}</flux:text>
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
                            <flux:button size="xs" variant="ghost" wire:click="$set('replyTo', {{ $comment->id }})">{{ __('Reply') }}</flux:button>
                            @unless ($comment->resolved_at)
                                <flux:button size="xs" variant="ghost" wire:click="resolve({{ $comment->id }})">{{ __('Resolve') }}</flux:button>
                            @endunless
                        </div>
                    @endif
                @endif
            </flux:card>
        @empty
            <flux:text size="sm">{{ __('No comments yet.') }}</flux:text>
        @endforelse

        @if ($this->canComment)
            <form wire:submit="addComment" class="space-y-2">
                <div class="flex gap-3">
                    <flux:select wire:model="commentKind" size="sm" class="max-w-40">
                        @foreach (\App\Enums\CommentKind::cases() as $kind)
                            <flux:select.option :value="$kind->value">{{ ucfirst($kind->value) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:textarea wire:model="commentBody" rows="3" :placeholder="__('Ask a question, suggest a change… (Markdown)')" />
                <flux:button size="sm" type="submit">{{ __('Comment') }}</flux:button>
            </form>
        @endif
    </div>

    {{-- History --}}
    <div class="space-y-3">
        <flux:heading size="lg" level="2">{{ __('History') }}</flux:heading>
        <div class="divide-y divide-zinc-100 rounded-lg border border-zinc-200 text-sm dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($this->revisions->reverse() as $revision)
                @php($previous = $this->revisions->firstWhere('number', $revision->number - 1))
                <div class="flex flex-wrap items-center justify-between gap-2 px-3 py-2" wire:key="rev-{{ $revision->id }}">
                    <span>
                        <strong>{{ __('Revision :n', ['n' => $revision->number]) }}</strong>
                        · {{ $revision->authorUser?->name ?? $revision->authorParty->name }} ({{ $revision->authorParty->name }})
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
</section>
