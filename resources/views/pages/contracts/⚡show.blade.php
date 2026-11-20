<?php

use App\Actions\Contracts\ContractReadiness;
use App\Actions\Contracts\SetContractStatus;
use App\Actions\Contracts\SignOffContract;
use App\Actions\Contracts\StartNewVersion;
use App\Actions\Contracts\WithdrawSignOff;
use App\Actions\Phases\CreatePhase;
use App\Actions\Phases\RenamePhase;
use App\Actions\Phases\ReorderPhases;
use App\Actions\Steps\CreateStep;
use App\Actions\Steps\MoveStep;
use App\Actions\Steps\ReorderSteps;
use App\Enums\ContractAbility;
use App\Enums\ContractStatus;
use App\Livewire\Concerns\ActsOnContract;
use App\Livewire\Concerns\RunsActions;
use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Checklist;
use App\Models\Comment;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;
use App\Support\ActivityDescription;
use App\Support\ContractAccess;
use App\Support\ContractPlan;
use App\Support\RandomId;
use App\Support\ReadinessReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    use ActsOnContract, RunsActions;

    public Contract $contract;

    /** Fingerprint of the state the person reviewed; a sign-off submits exactly this. */
    public string $reviewedFingerprint = '';

    /** Someone else changed the agreed content while this page was open. */
    public bool $changedSinceReview = false;

    /** @var list<string> activity ids received since the review went stale */
    public array $changes = [];

    public string $newPhaseTitle = '';

    public ?string $addingStepTo = null;

    public string $newStepTitle = '';

    public string $newStepDescription = '';

    public ?string $renamingPhase = null;

    public string $phaseTitle = '';

    public bool $showArchived = false;

    /** "steps" (the working view), "plan" (the whole contract as one document) or "checklist-{id}"; ?tab= in the URL. */
    #[Url(except: 'steps')]
    public string $tab = 'steps';

    /** Which steps to list: all, "unapproved" (missing any required approval) or "mine" (missing the acting party's). */
    #[Url(as: 'show', except: 'all')]
    public string $show = 'all';

    public function mount(Contract $contract): void
    {
        Gate::authorize('view', $contract);
        $this->contract = $contract;
        $this->initialiseActingParty();
    }

    #[Computed]
    public function report(): ReadinessReport
    {
        return ContractReadiness::report($this->contract);
    }

    /**
     * @return Collection<int, Phase>
     */
    #[Computed]
    public function phases(): Collection
    {
        return $this->contract->phases()
            ->with(['steps' => fn ($steps) => $steps->active()->with('currentRevision')])
            ->get();
    }

    /**
     * @return Collection<int, Party>
     */
    #[Computed]
    public function requiredParties(): Collection
    {
        // Same column order as each step's ticks (pages::contracts.step).
        return Party::query()->whereIn('id', $this->report->requiredPartyIds)->orderBy('name')->orderBy('id')->get();
    }

    /**
     * @return array<string, true> "stepId:partyId" for approvals that count
     */
    #[Computed]
    public function approved(): array
    {
        $outstanding = collect($this->report->outstanding)->map(fn (array $o) => $o['step_id'].':'.$o['party_id'])->flip();
        $approved = [];
        foreach ($this->phases as $phase) {
            foreach ($phase->steps as $step) {
                foreach ($this->report->requiredPartyIds as $partyId) {
                    if (! $outstanding->has($step->id.':'.$partyId)) {
                        $approved[$step->id.':'.$partyId] = true;
                    }
                }
            }
        }

        return $approved;
    }

    /**
     * Per stage: how many steps every required party approved, and how many each party approved.
     *
     * @return array<string, array{steps: int, approved: int, byParty: array<string, int>}>
     */
    #[Computed]
    public function stageCounts(): array
    {
        $counts = [];
        foreach ($this->phases as $phase) {
            $byParty = array_fill_keys($this->report->requiredPartyIds, 0);
            $approved = 0;
            foreach ($phase->steps as $step) {
                $all = $byParty !== [];
                foreach (array_keys($byParty) as $partyId) {
                    if (isset($this->approved[$step->id.':'.$partyId])) {
                        $byParty[$partyId]++;
                    } else {
                        $all = false;
                    }
                }
                $approved += $all ? 1 : 0;
            }
            $counts[$phase->id] = ['steps' => $phase->steps->count(), 'approved' => $approved, 'byParty' => $byParty];
        }

        return $counts;
    }

    #[Computed]
    public function archivedCount(): int
    {
        return $this->contract->steps()->whereNotNull('archived_at')->count();
    }

    /**
     * @return Collection<int, Step>
     */
    #[Computed]
    public function archivedSteps(): Collection
    {
        return $this->contract->steps()->whereNotNull('archived_at')->orderBy('archived_at')->get();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return ! $this->contract->isLocked() && $this->allows(ContractAbility::Edit);
    }

    #[Computed]
    public function actingIsRequired(): bool
    {
        $party = $this->actor()->party;

        return $party !== null && in_array($party->id, $this->report->requiredPartyIds, true)
            && ContractAccess::allows($this->actor(), $this->contract, ContractAbility::Approve);
    }

    /**
     * The steps the "Show" filter keeps, as a set of ids; null when every step is shown.
     *
     * @return array<string, true>|null
     */
    #[Computed]
    public function shownStepIds(): ?array
    {
        $party = $this->actor()->party;
        $partyIds = match ($this->show) {
            'unapproved' => $this->report->requiredPartyIds,
            'mine' => $this->actingIsRequired && $party !== null ? [$party->id] : [],
            default => [],
        };
        if ($partyIds === []) {
            return null;
        }

        $shown = [];
        foreach ($this->phases as $phase) {
            foreach ($phase->steps as $step) {
                foreach ($partyIds as $partyId) {
                    if (! isset($this->approved[$step->id.':'.$partyId])) {
                        $shown[$step->id] = true;
                        break;
                    }
                }
            }
        }

        return $shown;
    }

    #[Computed]
    public function actingHasSignedOff(): bool
    {
        return in_array($this->actor()->party?->id, $this->report->signedOffPartyIds, true);
    }

    #[Computed]
    public function nextVersion(): ?Contract
    {
        return Contract::query()->where('previous_version_id', $this->contract->id)->first();
    }

    /**
     * The versions of this contract the user can see, oldest first.
     *
     * @return Collection<int, Contract>
     */
    #[Computed]
    public function versions(): Collection
    {
        $versions = $this->contract->versions();
        $visible = Contract::query()->visibleTo(Auth::user())->whereIn('id', $versions->pluck('id'))->pluck('id')->all();

        return $versions->filter(fn (Contract $version) => in_array($version->id, $visible, true))->values();
    }

    /**
     * Only the latest version's status can change, by anyone who may edit it apart from the lock (D14).
     */
    #[Computed]
    public function canSetStatus(): bool
    {
        return $this->nextVersion === null && $this->allows(ContractAbility::Edit);
    }

    /**
     * Live update from someone's change (Phase 5). The page re-renders and tells the step concerned (or every step)
     * to refresh. If the contract's fingerprint moved away from what this person reviewed because of someone else's
     * change, Sign off waits until they acknowledge it.
     */
    #[On('echo-private:contract.{contract.id},.activity')]
    public function onActivity(array $event): void
    {
        $this->contract->refresh();
        unset($this->report);

        $activity = RandomId::isValid($event['id'] ?? null)
            ? ActivityEvent::query()->where('contract_id', $this->contract->id)->with('subject')->find($event['id'])
            : null;
        $this->refreshStepsFor($activity);

        if ($activity?->actor_user_id === Auth::id()) {
            return;
        }

        if (! $this->contract->isAgreed() && $this->report->fingerprint !== $this->reviewedFingerprint) {
            $this->changedSinceReview = true;
        }

        if ($this->changedSinceReview && $activity !== null) {
            $this->changes = array_slice(array_values(array_unique([...$this->changes, $activity->id])), -20);
        }
    }

    /**
     * Steps are their own components: an activity on one step refreshes that step; one that changes columns or the
     * lock refreshes them all; anything else (phases, order, sign-offs) is this page's own business.
     */
    private function refreshStepsFor(?ActivityEvent $activity): void
    {
        $stepId = self::stepIdOf($activity?->subject);

        if ($activity !== null && str_starts_with($activity->type, 'checklist.')) {
            $this->dispatch('checklist-refresh');
        } elseif ($stepId !== null) {
            $this->dispatch('step-refresh.'.$stepId);
        } elseif ($activity === null || str_starts_with($activity->type, 'party.') || in_array($activity->type, ['contract.agreed', 'contract.status_changed'], true)) {
            $this->dispatch('steps-refresh');
        }
    }

    /**
     * A step on this page changed readiness (approval, edit, archive); re-render the sign-off panel and stage counts.
     */
    #[On('step-updated')]
    public function stepUpdated(): void {}

    /**
     * The acting party decides which tick each step lets this person click.
     */
    public function updated(string $name): void
    {
        if ($name === 'actingPartyId') {
            $this->dispatch('steps-refresh');
        }
    }

    public function reviewAgain(): void
    {
        $this->changedSinceReview = false;
        $this->changes = [];
    }

    /**
     * What changed while the person was reviewing; each change to a step links to it (with its comments for comments).
     *
     * @return list<array{text: string, hash: string|null}>
     */
    #[Computed]
    public function changeDescriptions(): array
    {
        return ActivityEvent::query()
            ->where('contract_id', $this->contract->id)
            ->whereIn('id', $this->changes)
            ->with(['actorUser', 'actorParty', 'subject'])
            ->orderBy('position')
            ->get()
            ->map(function (ActivityEvent $event): array {
                $stepId = self::stepIdOf($event->subject);

                return [
                    'text' => ActivityDescription::for($event),
                    'hash' => $stepId === null ? null : 'step-'.$stepId.($event->subject instanceof Comment ? '-comments' : ''),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * The step an activity is about: the step itself, or the step of a comment or approval.
     */
    private static function stepIdOf(mixed $subject): ?string
    {
        return match (true) {
            $subject instanceof Step => $subject->id,
            $subject instanceof Comment, $subject instanceof Approval => $subject->step_id,
            default => null,
        };
    }

    public function signOff(): void
    {
        // The last sign-off agrees and locks the contract, so the steps' controls go.
        if ($this->attempt(fn () => app(SignOffContract::class)->handle($this->actor(), $this->contract, $this->reviewedFingerprint), __('Signed off.')) !== null) {
            $this->dispatch('steps-refresh');
        }
    }

    public function withdrawSignOff(): void
    {
        $this->attempt(fn () => app(WithdrawSignOff::class)->handle($this->actor(), $this->contract), __('Sign-off withdrawn.'));
    }

    public function startNewVersion(): void
    {
        $next = $this->attempt(fn () => app(StartNewVersion::class)->handle($this->actor(), $this->contract));

        if ($next !== null) {
            $this->redirectRoute('contracts.show', ['contract' => $next], navigate: true);
        }
    }

    public function setStatus(string $status): void
    {
        $to = ContractStatus::tryFrom($status);
        if ($to === null) {
            $this->refused(__('Unknown status.'), 422);

            return;
        }

        if ($this->attempt(fn () => app(SetContractStatus::class)->handle($this->actor(), $this->contract, $to), __('Status changed.')) !== null) {
            $this->dispatch('steps-refresh');
        }
    }

    public function addPhase(): void
    {
        $this->validate(['newPhaseTitle' => ['required', 'string', 'max:200']]);

        if ($this->attempt(fn () => app(CreatePhase::class)->handle($this->actor(), $this->contract, $this->newPhaseTitle)) !== null) {
            $this->reset('newPhaseTitle');
        }
    }

    public function startRenaming(string $phaseId): void
    {
        $this->renamingPhase = $phaseId;
        $this->phaseTitle = $this->contract->findPhase($phaseId)->title;
    }

    /**
     * Enter or leaving the field saves; Escape cancels. An unchanged title is a no-op.
     */
    public function renamePhase(): void
    {
        if ($this->renamingPhase === null) {
            return;
        }

        $this->validate(['phaseTitle' => ['required', 'string', 'max:200']]);

        $phase = $this->contract->findPhase($this->renamingPhase);
        $this->attempt(fn () => app(RenamePhase::class)->handle($this->actor(), $phase, $this->phaseTitle));
        $this->reset('renamingPhase', 'phaseTitle');
    }

    public function cancelRenaming(): void
    {
        $this->reset('renamingPhase', 'phaseTitle');
        $this->resetValidation('phaseTitle');
    }

    public function movePhase(string $phaseId, int $offset): void
    {
        $ids = $this->contract->phases()->pluck('id')->all();
        $from = array_search($phaseId, $ids, true);
        $to = $from === false ? false : $from + $offset;

        if ($from === false || $to < 0 || $to >= count($ids)) {
            return;
        }

        [$ids[$from], $ids[$to]] = [$ids[$to], $ids[$from]];
        $this->attempt(fn () => app(ReorderPhases::class)->handle($this->actor(), $this->contract, $ids));
    }

    public function startAddingStep(string $phaseId): void
    {
        $this->addingStepTo = $phaseId;
        $this->reset('newStepTitle', 'newStepDescription');
    }

    public function addStep(): void
    {
        $this->validate([
            'newStepTitle' => ['required', 'string', 'max:200'],
            'newStepDescription' => ['nullable', 'string', 'max:20000'],
        ]);

        if ($this->addingStepTo === null) {
            return;
        }

        $phase = $this->contract->findPhase($this->addingStepTo);
        $step = $this->attempt(fn () => app(CreateStep::class)->handle($this->actor(), $phase, $this->newStepTitle, $this->newStepDescription));

        if ($step !== null) {
            $this->reset('addingStepTo', 'newStepTitle', 'newStepDescription');
        }
    }

    /**
     * Drag and drop: $position is 0-based within the target phase's active steps.
     */
    public function sortStep(string $stepId, int $position, string $phaseId): void
    {
        $step = $this->contract->findStep($stepId);
        $target = $this->contract->findPhase($phaseId);

        if ($step->phase_id !== $target->id) {
            $this->attempt(fn () => app(MoveStep::class)->handle($this->actor(), $step, $target, $position + 1));

            return;
        }

        $ids = Step::query()->where('phase_id', $target->id)->active()->whereKeyNot($step->id)->orderBy('position')->pluck('id')->all();
        array_splice($ids, max(0, min($position, count($ids))), 0, [$step->id]);
        $this->attempt(fn () => app(ReorderSteps::class)->handle($this->actor(), $target, $ids));
    }

    /**
     * The plan document (App\Support\ContractPlan), only built on the Plan tab.
     */
    #[Computed]
    public function plan(): string
    {
        return ContractPlan::markdown($this->contract, $this->report);
    }

    /**
     * The version's checklists for their tabs (oldest first), with done and total items.
     *
     * @return Collection<int, array{id: string, name: string, done: int, total: int}>
     */
    #[Computed]
    public function checklistTabs(): Collection
    {
        return Checklist::query()->where('contract_id', $this->contract->id)
            ->withCount(['items as total_items', 'items as done_items' => fn ($items) => $items->whereNotNull('checklist_items.checked_at')])
            ->orderBy('created_at')->orderBy('name')
            ->get()
            ->map(fn (Checklist $checklist) => [
                'id' => $checklist->id,
                'name' => $checklist->name,
                'done' => (int) $checklist->getAttribute('done_items'),
                'total' => (int) $checklist->getAttribute('total_items'),
            ]);
    }

    /**
     * A tick in a Checklist tab changes the count in its label.
     */
    #[On('checklist-updated')]
    public function checklistUpdated(): void {}

    public function render()
    {
        // Actions may have changed the contract during this request.
        $this->contract->refresh();
        unset($this->report, $this->changeDescriptions, $this->phases, $this->requiredParties, $this->approved, $this->stageCounts, $this->archivedCount, $this->archivedSteps, $this->canEdit, $this->actingIsRequired, $this->actingHasSignedOff, $this->nextVersion, $this->versions, $this->canSetStatus, $this->plan, $this->checklistTabs, $this->shownStepIds);
        // Tabs: steps, plan, or checklist-{id} for a checklist of this version (one that was replaced is gone).
        $checklistTab = str_starts_with($this->tab, 'checklist-') && $this->checklistTabs->contains('id', substr($this->tab, 10));
        if (! in_array($this->tab, ['steps', 'plan'], true) && ! $checklistTab) {
            $this->tab = 'steps';
        }
        // "Awaiting me" only for someone acting as a required approver; the filters need required approvers at all.
        if (! in_array($this->show, ['all', 'unapproved', 'mine'], true) || $this->report->requiredPartyIds === []
            || ($this->show === 'mine' && ! $this->actingIsRequired)) {
            $this->show = 'all';
            unset($this->shownStepIds);
        }
        if (! $this->changedSinceReview) {
            $this->reviewedFingerprint = $this->report->fingerprint;
        }

        return $this->view()->title($this->contract->title.' v'.$this->contract->version);
    }
}; ?>

<section class="w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-contract-breadcrumbs :contract="$contract" />
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $contract->title }}</flux:heading>
                @if ($this->versions->count() > 1)
                    <flux:dropdown data-test="version-switcher">
                        <flux:button size="sm" icon:trailing="chevron-down">v{{ $contract->version }}</flux:button>
                        <flux:menu>
                            @foreach ($this->versions as $version)
                                <flux:menu.item :href="route('contracts.show', $version)" wire:navigate wire:key="version-{{ $version->id }}" :icon="$version->id === $contract->id ? 'check' : null">
                                    {{ __('Version :v', ['v' => $version->version]) }}
                                    <span class="ms-2 text-zinc-500">{{ collect([$version->isAgreed() ? __('Agreed') : __('Not agreed'), $version->isOpen() ? null : __($version->status->label())])->filter()->implode(' · ') }}</span>
                                </flux:menu.item>
                            @endforeach
                        </flux:menu>
                    </flux:dropdown>
                @else
                    <flux:badge>v{{ $contract->version }}</flux:badge>
                @endif
                <flux:badge :color="$this->report->state->colour()" data-test="contract-state">{{ $this->report->state->label() }}</flux:badge>
                @if (! $contract->isOpen())
                    <flux:badge :color="$contract->status->colour()" data-test="contract-status">{{ __($contract->status->label()) }}</flux:badge>
                @endif
                @if ($contract->require_human_signoff)
                    <flux:badge color="sky" icon="user">{{ __('Human sign-off') }}</flux:badge>
                @endif
            </div>
        </div>
        <div class="flex items-end gap-3">
            <x-acting-as :options="$this->actingOptions" />
            @if ($this->canSetStatus)
                <flux:dropdown align="end" data-test="status-menu">
                    <flux:button size="sm" icon:trailing="chevron-down">{{ __('Status: :status', ['status' => __($contract->status->label())]) }}</flux:button>
                    <flux:menu>
                        @foreach (\App\Enums\ContractStatus::cases() as $status)
                            @if ($status === \App\Enums\ContractStatus::Open)
                                <flux:menu.item wire:click="setStatus('{{ $status->value }}')" :disabled="$contract->status === $status">{{ __('Open') }}</flux:menu.item>
                            @else
                                <flux:menu.item wire:click="setStatus('{{ $status->value }}')" :disabled="$contract->status === $status"
                                                wire:confirm="{{ __('Mark this contract :status? It becomes read-only until it is reopened.', ['status' => strtolower(__($status->label()))]) }}">{{ __($status->label()) }}</flux:menu.item>
                            @endif
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            @endif
            @if ($this->isOwner)
                <flux:button size="sm" icon="cog-6-tooth" :href="route('contracts.settings', $contract)" wire:navigate>{{ __('Settings') }}</flux:button>
            @endif
        </div>
    </div>

    @if (! $contract->isOpen())
        <flux:callout :icon="$contract->status === \App\Enums\ContractStatus::Complete ? 'check-circle' : 'x-circle'" color="zinc"
                      :heading="__('Marked :status', ['status' => strtolower(__($contract->status->label()))])" data-test="closed-callout">
            <flux:callout.text>
                {{ $contract->isAgreed() ? __('Reopening it does not unlock it: it is agreed, so any change needs a new version.') : __('This version is read-only until it is reopened.') }}
            </flux:callout.text>
            @if ($this->canSetStatus)
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="setStatus('open')">{{ __('Reopen') }}</flux:button>
                </x-slot>
            @endif
        </flux:callout>
    @endif

    @if ($contract->isAgreed())
        <flux:callout icon="lock-closed" color="green" :heading="__('Agreed on :date', ['date' => $contract->agreed_at->toFormattedDayDateString()])">
            <flux:callout.text>{{ __('This version is locked. Any change needs a new version.') }}</flux:callout.text>
            <x-slot name="actions">
                @if ($this->nextVersion)
                    <flux:button size="sm" :href="route('contracts.show', $this->nextVersion)" wire:navigate>{{ __('Open version :v', ['v' => $this->nextVersion->version]) }}</flux:button>
                @elseif ($this->isOwner)
                    <flux:button size="sm" variant="primary" wire:click="startNewVersion" wire:confirm="{{ __('Start a new version? It copies the current content and approvals; every required party signs off again.') }}">{{ __('Start new version') }}</flux:button>
                @endif
            </x-slot>
        </flux:callout>
    @endif

    {{-- Readiness and sign-off --}}
    <flux:card class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <flux:heading size="lg" level="2">{{ __('Sign-off') }}</flux:heading>
                <flux:text size="sm">
                    @if ($contract->isAgreed())
                        {{ __('Every required party signed off.') }}
                    @elseif (! $contract->isOpen())
                        {{ __('Sign-off is paused until the contract is reopened.') }}
                    @elseif ($this->report->requiredPartyIds === [])
                        {{ __('No required approvers yet. An owner sets them in the contract settings.') }}
                    @elseif ($this->report->activeStepCount === 0)
                        {{ __('Add steps before the contract can be signed off.') }}
                    @elseif ($this->report->isReady())
                        {{ __('Every step is approved. Each required party now signs off the whole contract.') }}
                    @else
                        {{ trans_choice(':count approval outstanding|:count approvals outstanding', count($this->report->outstanding)) }}
                    @endif
                </flux:text>
            </div>

            @if ($this->actingIsRequired && ! $contract->isLocked())
                <div class="flex items-center gap-2">
                    @if ($this->actingHasSignedOff)
                        <flux:badge color="green" icon="check">{{ __('You signed off') }}</flux:badge>
                        <flux:button size="sm" variant="ghost" wire:click="withdrawSignOff">{{ __('Withdraw') }}</flux:button>
                    @elseif ($changedSinceReview)
                        <flux:button variant="primary" disabled data-test="sign-off-button">{{ __('Contract changed; review again') }}</flux:button>
                    @else
                        <flux:button variant="primary" wire:click="signOff" :disabled="! $this->report->isReady()" data-test="sign-off-button">
                            {{ __('Sign off contract') }}
                        </flux:button>
                    @endif
                </div>
            @endif
        </div>

        @if ($changedSinceReview && ! $contract->isAgreed())
            <flux:callout icon="arrow-path" color="amber" :heading="__('The contract changed while you were reviewing it')" data-test="changed-since-review">
                <flux:callout.text>
                    {{-- Links go to the step on the Steps tab without reloading, so the frozen review state is kept. If the
                         hash is already this step's, re-announce it so the step shows again. --}}
                    <ul class="list-disc ps-5" x-data="{ go(hash) { $wire.set('tab', 'steps').then(() => { if (location.hash === '#' + hash) { window.dispatchEvent(new HashChangeEvent('hashchange')) } else { location.hash = hash } }) } }">
                        @foreach ($this->changeDescriptions as $change)
                            <li>
                                @if ($change['hash'] !== null)
                                    <a href="#{{ $change['hash'] }}" x-on:click.prevent="go(@js($change['hash']))" class="underline decoration-dotted underline-offset-2 hover:decoration-solid" data-test="change-link">{{ $change['text'] }}</a>
                                @else
                                    {{ $change['text'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </flux:callout.text>
                <x-slot name="actions">
                    <flux:button size="sm" wire:click="reviewAgain">{{ __('I have reviewed the changes') }}</flux:button>
                </x-slot>
            </flux:callout>
        @endif

        @if ($this->requiredParties->isNotEmpty())
            <div class="flex flex-wrap gap-2">
                @foreach ($this->requiredParties as $party)
                    @php($signed = in_array($party->id, $this->report->signedOffPartyIds, true))
                    <flux:badge :color="$signed ? 'green' : 'zinc'" :icon="$signed ? 'check-circle' : 'clock'" wire:key="required-{{ $party->id }}">
                        {{ $party->name }} · {{ $signed ? __('signed off') : __('not signed off') }}
                    </flux:badge>
                @endforeach
            </div>
        @endif

        <flux:text size="xs" class="font-mono">{{ __('Reviewed state') }}: {{ substr($this->report->fingerprint, 0, 12) }}</flux:text>
    </flux:card>

    {{-- Tabs: the working view, the contract as one plan document, and one per checklist ("Checklist: API team").
         They wrap onto a second line rather than scroll when there are many. --}}
    @php($tabs = collect([
        ['key' => 'steps', 'label' => __('Steps'), 'counts' => null],
        ['key' => 'plan', 'label' => __('View as plan'), 'counts' => null],
        ...$this->checklistTabs->map(fn (array $c) => ['key' => 'checklist-'.$c['id'], 'label' => __('Checklist: :name', ['name' => $c['name']]), 'counts' => $c])->all(),
    ]))
    <div role="tablist" class="flex flex-wrap gap-x-6 gap-y-1 border-b border-zinc-200 dark:border-zinc-700" data-test="contract-tabs">
        @foreach ($tabs as $item)
            <button type="button" role="tab" aria-selected="{{ $tab === $item['key'] ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $item['key'] }}')" wire:key="tab-{{ $item['key'] }}"
                    data-test="{{ $item['counts'] === null ? 'tab-'.$item['key'] : 'tab-checklist' }}"
                    @class(['-mb-px flex max-w-72 items-center border-b-2 px-1 pb-2 text-sm font-medium', 'border-zinc-800 text-zinc-900 dark:border-white dark:text-white' => $tab === $item['key'], 'border-transparent text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $tab !== $item['key']])>
                <span class="truncate" title="{{ $item['label'] }}">{{ $item['label'] }}</span>
                @if ($item['counts'] !== null)
                    @php($complete = $item['counts']['total'] > 0 && $item['counts']['done'] === $item['counts']['total'])
                    <span @class(['ms-1 shrink-0 rounded-full px-1.5 py-0.5 text-xs tabular-nums', 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' => $complete, 'bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300' => ! $complete])>{{ $item['counts']['done'] }}/{{ $item['counts']['total'] }}</span>
                @endif
            </button>
        @endforeach
    </div>

    @if (str_starts_with($tab, 'checklist-'))
        <livewire:pages::contracts.checklist :$contract :checklist-id="substr($tab, 10)" :key="$tab" />
    @elseif ($tab === 'plan')
        <flux:card class="space-y-4" data-test="plan">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:text size="sm">{{ __('Every phase and step, as one document. Archived steps are left out.') }}</flux:text>
                <flux:button size="sm" icon="arrow-down-tray" :href="route('contracts.plan', $contract)" data-test="download-plan">{{ __('Download .md') }}</flux:button>
            </div>
            <x-markdown :source="$this->plan" class="border-t border-zinc-100 pt-4 dark:border-zinc-700" />
        </flux:card>
    @else
    {{-- Phases and steps in one panel. Columns line up with each step's row in pages::contracts.step. --}}
    <style>{!! \App\Support\RevisionDiff::styles() !!}</style>
    {{-- A link to a step the filter hides shows all steps again; a link to an archived step opens the archived list. --}}
    <flux:card class="space-y-2 overflow-x-auto" data-test="steps-panel"
               x-data="{ revealLinked() {
                   const m = location.hash.match(/^#(step-[0-9a-z]+)/);
                   if (! m || document.getElementById(m[1])) return;
                   const archived = () => { if (! document.getElementById(m[1]) && @js($this->archivedCount > 0)) $wire.set('showArchived', true) };
                   $wire.show !== 'all' ? $wire.set('show', 'all').then(archived) : archived();
               } }"
               x-init="revealLinked()" x-on:hashchange.window="revealLinked()">
        <div class="flex flex-wrap items-center justify-between gap-2" x-data="{ phasesOpen: true, stepsOpen: false }">
            <flux:heading size="lg" level="2">{{ __('Steps') }}</flux:heading>
            <div class="flex flex-wrap items-center gap-1">
                @if ($this->requiredParties->isNotEmpty())
                    <flux:radio.group wire:model.live="show" variant="segmented" size="sm" class="me-2" data-test="step-filter">
                        <flux:radio value="all" :label="__('All')" />
                        <flux:radio value="unapproved" :label="__('Not fully approved')" />
                        @if ($this->actingIsRequired)
                            <flux:radio value="mine" :label="__('Awaiting me')" />
                        @endif
                    </flux:radio.group>
                @endif
                <flux:button size="xs" variant="ghost" x-on:click="stepsOpen = ! stepsOpen; $dispatch('steps-toggle', { phase: null, open: stepsOpen }); if (stepsOpen) { phasesOpen = true; $dispatch('phases-toggle', { open: true }) }">
                    <span x-text="stepsOpen ? @js(__('Hide all step text')) : @js(__('Show all step text'))">{{ __('Show all step text') }}</span>
                </flux:button>
                <flux:button size="xs" variant="ghost" x-on:click="phasesOpen = ! phasesOpen; $dispatch('phases-toggle', { open: phasesOpen })" data-test="toggle-all-phases">
                    <span x-text="phasesOpen ? @js(__('Collapse all phases')) : @js(__('Expand all phases'))">{{ __('Collapse all phases') }}</span>
                </flux:button>
            </div>
        </div>

        @if ($this->shownStepIds === [])
            <flux:text size="sm" data-test="filter-empty">
                {{ $show === 'mine' ? __('Nothing awaits your party\'s approval.') : __('Every step is approved by every required party.') }}
            </flux:text>
        @endif

        {{-- Wide enough for the fixed columns plus a readable title column; titles wrap rather than widen the list,
             so the party columns stay in view. The panel scrolls sideways only below this width. --}}
        @php($fixedColumns = ($this->canEdit ? 1.5 : 0) + 3 + 3.5 + 6.5 * $this->requiredParties->count() + 2.5)
        <div class="text-sm" style="min-width: {{ 14 + $fixedColumns }}rem">
            <div class="flex items-center gap-2 border-b border-zinc-200 pb-2 text-xs font-medium text-zinc-500 dark:border-zinc-700">
                @if ($this->canEdit)<span class="w-4 shrink-0"></span>@endif
                <span class="min-w-0 flex-1">{{ __('Step') }}</span>
                <span class="w-10 shrink-0 text-center">{{ __('Rev') }}</span>
                <span class="flex w-12 shrink-0 justify-center" title="{{ __('Comments') }}"><flux:icon.chat-bubble-left variant="micro" /></span>
                @foreach ($this->requiredParties as $party)
                    <span class="w-24 shrink-0 truncate text-center" title="{{ $party->name }}" wire:key="col-{{ $party->id }}">{{ $party->name }}</span>
                @endforeach
                <span class="w-8 shrink-0"></span>
            </div>

            @forelse ($this->phases as $phase)
                @php($counts = $this->stageCounts[$phase->id])
                <section wire:key="phase-{{ $phase->id }}" data-phase="{{ $phase->id }}" data-test="phase"
                         x-data="{ open: true, focusLinked() { const m = location.hash.match(/^#(step-[0-9a-z]+)/); if (m) this.open = this.$el.querySelector('#' + m[1]) !== null } }"
                         x-init="focusLinked()" x-on:hashchange.window="focusLinked()" x-on:phases-toggle.window="open = $event.detail.open" x-on:reveal-phase="open = true"
                         class="border-b border-zinc-200 last:border-0 dark:border-zinc-700">
                    {{-- Phase header: title (click to rename), progress, per-party counts. --}}
                    <div class="flex items-center gap-2 bg-zinc-50 py-2 dark:bg-zinc-800/60">
                        @if ($this->canEdit)<span class="w-4 shrink-0"></span>@endif
                        <div class="flex min-w-0 flex-1 flex-wrap items-center gap-x-1.5 gap-y-1">
                            <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-label="{{ __('Collapse or expand phase') }}" class="shrink-0">
                                <flux:icon.chevron-down variant="mini" class="text-zinc-500 transition-transform" x-bind:class="! open && '-rotate-90'" />
                            </button>
                            @if ($renamingPhase === $phase->id)
                                <form wire:submit="renamePhase" class="min-w-0 flex-1" x-data="{ cancelled: false }"
                                      x-init="$nextTick(() => $el.querySelector('input')?.select())"
                                      x-on:keydown.escape.prevent="cancelled = true; $wire.cancelRenaming()"
                                      x-on:focusout="if (! cancelled) $wire.renamePhase()">
                                    <flux:input wire:model="phaseTitle" size="sm" :aria-label="__('Phase title')" />
                                </form>
                            @elseif ($this->canEdit)
                                <h3 class="min-w-0 wrap-anywhere">
                                    <button type="button" wire:click="startRenaming('{{ $phase->id }}')" title="{{ __('Click to rename') }}"
                                            class="text-start font-semibold decoration-dotted underline-offset-4 hover:underline" data-test="phase-title">{{ $phase->title }}</button>
                                </h3>
                            @else
                                <h3 class="min-w-0 font-semibold wrap-anywhere" data-test="phase-title">{{ $phase->title }}</h3>
                            @endif
                            @if ($counts['steps'] > 0 && $this->requiredParties->isNotEmpty())
                                <flux:badge size="sm" :color="$counts['approved'] === $counts['steps'] ? 'green' : 'zinc'" data-test="phase-progress">
                                    {{ __(':approved of :steps approved', ['approved' => $counts['approved'], 'steps' => $counts['steps']]) }}
                                </flux:badge>
                            @else
                                <flux:text size="sm">{{ trans_choice(':count step|:count steps', $counts['steps']) }}</flux:text>
                            @endif
                        </div>
                        <span class="w-10 shrink-0"></span>
                        <span class="w-12 shrink-0"></span>
                        @foreach ($this->requiredParties as $party)
                            @php($n = $counts['byParty'][$party->id] ?? 0)
                            <span wire:key="count-{{ $phase->id }}-{{ $party->id }}" title="{{ __(':party approved :n of :steps', ['party' => $party->name, 'n' => $n, 'steps' => $counts['steps']]) }}"
                                  @class(['w-24 shrink-0 text-center text-xs tabular-nums', 'text-green-600 dark:text-green-400' => $counts['steps'] > 0 && $n === $counts['steps'], 'text-zinc-500' => $n !== $counts['steps'] || $counts['steps'] === 0])>
                                {{ $n }}/{{ $counts['steps'] }}
                            </span>
                        @endforeach
                        <span class="w-8 shrink-0 text-end">
                            <flux:dropdown align="end">
                                <flux:button size="xs" variant="ghost" icon="ellipsis-horizontal" :aria-label="__('Phase actions')" />
                                <flux:menu>
                                    <flux:menu.item icon="arrows-pointing-out" x-on:click="open = true; $dispatch('steps-toggle', { phase: '{{ $phase->id }}', open: true })">{{ __('Expand all steps') }}</flux:menu.item>
                                    <flux:menu.item icon="arrows-pointing-in" x-on:click="$dispatch('steps-toggle', { phase: '{{ $phase->id }}', open: false })">{{ __('Collapse all steps') }}</flux:menu.item>
                                    @if ($this->canEdit)
                                        <flux:menu.separator />
                                        <flux:menu.item icon="chevron-up" wire:click="movePhase('{{ $phase->id }}', -1)" :disabled="$loop->first">{{ __('Move phase up') }}</flux:menu.item>
                                        <flux:menu.item icon="chevron-down" wire:click="movePhase('{{ $phase->id }}', 1)" :disabled="$loop->last">{{ __('Move phase down') }}</flux:menu.item>
                                    @endif
                                </flux:menu>
                            </flux:dropdown>
                        </span>
                    </div>
                    @error('phaseTitle')
                        @if ($renamingPhase === $phase->id)<flux:text size="sm" class="text-red-600">{{ $message }}</flux:text>@endif
                    @enderror

                    {{-- With a filter, only the steps it keeps are listed; a phase with none keeps just its header. Dragging
                         is off while filtering, since positions count the hidden steps too. --}}
                    @php($filtering = $this->shownStepIds !== null)
                    @php($shownSteps = $filtering ? $phase->steps->filter(fn ($s) => isset($this->shownStepIds[$s->id]))->values() : $phase->steps)
                    @php($sortable = $this->canEdit && ! $filtering)
                    @if (! $filtering || $shownSteps->isNotEmpty())
                        <div x-show="open">
                            <div class="divide-y divide-zinc-100 dark:divide-zinc-700/60" @if ($sortable) wire:sort="sortStep" wire:sort:group="steps" wire:sort:group-id="{{ $phase->id }}" @endif>
                                @foreach ($shownSteps as $step)
                                    <div wire:key="step-{{ $step->id }}" @if ($sortable) wire:sort:item="{{ $step->id }}" @endif class="flex items-start gap-2">
                                        @if ($sortable)
                                            <span class="w-4 shrink-0 cursor-grab pt-2.5 text-zinc-400" wire:sort:handle title="{{ __('Drag to move') }}"><flux:icon.bars-3 variant="micro" /></span>
                                        @elseif ($this->canEdit)
                                            <span class="w-4 shrink-0"></span>
                                        @endif
                                        <livewire:pages::contracts.step :$contract :$step :key="'step-component-'.$step->id" />
                                    </div>
                                @endforeach
                            </div>
                            @if ($phase->steps->isEmpty())
                                <flux:text size="sm" class="py-2 ps-6">{{ __('No steps in this phase.') }}</flux:text>
                            @endif

                            @if ($this->canEdit)
                                <div class="py-2 ps-6">
                                    @if ($addingStepTo === $phase->id)
                                        <form wire:submit="addStep" class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                            <flux:input wire:model="newStepTitle" :label="__('Step title')" />
                                            <flux:textarea wire:model="newStepDescription" :label="__('Description (Markdown)')" rows="4" />
                                            <div class="flex gap-2">
                                                <flux:button size="sm" type="submit" variant="primary">{{ __('Add step') }}</flux:button>
                                                <flux:button size="sm" variant="ghost" wire:click="$set('addingStepTo', null)">{{ __('Cancel') }}</flux:button>
                                            </div>
                                        </form>
                                    @else
                                        <flux:button size="xs" variant="subtle" icon="plus" wire:click="startAddingStep('{{ $phase->id }}')">{{ __('Add step') }}</flux:button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endif
                </section>
            @empty
                <flux:text class="py-3">{{ __('No phases yet.') }}</flux:text>
            @endforelse
        </div>

        @if ($this->canEdit)
            <form wire:submit="addPhase" class="flex items-end gap-3 pt-2">
                <flux:input wire:model="newPhaseTitle" :label="__('New phase')" :placeholder="__('Title')" class="flex-1" />
                <flux:button type="submit">{{ __('Add phase') }}</flux:button>
            </form>
        @endif

        @if ($this->archivedCount > 0)
            <div class="space-y-2 pt-2">
                <flux:button size="xs" variant="ghost" :icon="$showArchived ? 'chevron-down' : 'chevron-right'" wire:click="$toggle('showArchived')">
                    {{ trans_choice(':count archived step|:count archived steps', $this->archivedCount) }}
                </flux:button>
                @if ($showArchived)
                    <div class="divide-y divide-zinc-100 text-sm opacity-80 dark:divide-zinc-700/60" data-test="archived-steps">
                        @foreach ($this->archivedSteps as $step)
                            <div wire:key="archived-{{ $step->id }}" class="flex">
                                <livewire:pages::contracts.step :$contract :$step :key="'archived-component-'.$step->id" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </flux:card>
    @endif
</section>
