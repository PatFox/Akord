<?php

use App\Actions\Contracts\ContractReadiness;
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
use App\Livewire\Concerns\ActsOnContract;
use App\Livewire\Concerns\RunsActions;
use App\Models\Contract;
use App\Models\Party;
use App\Models\Phase;
use App\Models\Step;
use App\Support\ContractAccess;
use App\Support\ReadinessReport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    use ActsOnContract, RunsActions;

    public Contract $contract;

    /** Fingerprint of the state on screen; a sign-off submits exactly this. */
    public string $reviewedFingerprint = '';

    public string $newPhaseTitle = '';

    public ?int $addingStepTo = null;

    public string $newStepTitle = '';

    public string $newStepDescription = '';

    public ?int $renamingPhase = null;

    public string $phaseTitle = '';

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
        return Party::query()->whereIn('id', $this->report->requiredPartyIds)->orderBy('name')->get();
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

    #[Computed]
    public function archivedCount(): int
    {
        return $this->contract->steps()->whereNotNull('archived_at')->count();
    }

    #[Computed]
    public function canEdit(): bool
    {
        return ! $this->contract->isAgreed() && $this->allows(ContractAbility::Edit);
    }

    #[Computed]
    public function actingIsRequired(): bool
    {
        $party = $this->actor()->party;

        return $party !== null && in_array($party->id, $this->report->requiredPartyIds, true)
            && ContractAccess::allows($this->actor(), $this->contract, ContractAbility::Approve);
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

    public function signOff(): void
    {
        $this->attempt(fn () => app(SignOffContract::class)->handle($this->actor(), $this->contract, $this->reviewedFingerprint), __('Signed off.'));
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

    public function addPhase(): void
    {
        $this->validate(['newPhaseTitle' => ['required', 'string', 'max:200']]);

        if ($this->attempt(fn () => app(CreatePhase::class)->handle($this->actor(), $this->contract, $this->newPhaseTitle)) !== null) {
            $this->reset('newPhaseTitle');
        }
    }

    public function startRenaming(int $phaseId): void
    {
        $this->renamingPhase = $phaseId;
        $this->phaseTitle = $this->contract->findPhase($phaseId)->title;
    }

    public function renamePhase(): void
    {
        $this->validate(['phaseTitle' => ['required', 'string', 'max:200']]);

        if ($this->renamingPhase !== null) {
            $phase = $this->contract->findPhase($this->renamingPhase);
            $this->attempt(fn () => app(RenamePhase::class)->handle($this->actor(), $phase, $this->phaseTitle));
        }

        $this->reset('renamingPhase', 'phaseTitle');
    }

    public function movePhase(int $phaseId, int $offset): void
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

    public function startAddingStep(int $phaseId): void
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
    public function sortStep(int|string $stepId, int $position, int|string $phaseId): void
    {
        $step = $this->contract->findStep((int) $stepId);
        $target = $this->contract->findPhase((int) $phaseId);

        if ($step->phase_id !== $target->id) {
            $this->attempt(fn () => app(MoveStep::class)->handle($this->actor(), $step, $target, $position + 1));

            return;
        }

        $ids = Step::query()->where('phase_id', $target->id)->active()->whereKeyNot($step->id)->orderBy('position')->pluck('id')->all();
        array_splice($ids, max(0, min($position, count($ids))), 0, [$step->id]);
        $this->attempt(fn () => app(ReorderSteps::class)->handle($this->actor(), $target, $ids));
    }

    public function render()
    {
        // Actions may have changed the contract during this request.
        $this->contract->refresh();
        unset($this->report, $this->phases, $this->requiredParties, $this->approved, $this->archivedCount, $this->canEdit, $this->actingIsRequired, $this->actingHasSignedOff, $this->nextVersion);
        $this->reviewedFingerprint = $this->report->fingerprint;

        return $this->view()->title($this->contract->title.' v'.$this->contract->version);
    }
}; ?>

<section class="w-full max-w-6xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <x-contract-breadcrumbs :contract="$contract" />
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl" level="1">{{ $contract->title }}</flux:heading>
                <flux:badge>v{{ $contract->version }}</flux:badge>
                <flux:badge :color="$this->report->state->colour()" data-test="contract-state">{{ $this->report->state->label() }}</flux:badge>
                @if ($contract->require_human_signoff)
                    <flux:badge color="sky" icon="user">{{ __('Human sign-off') }}</flux:badge>
                @endif
            </div>
        </div>
        <div class="flex items-end gap-3">
            <x-acting-as :options="$this->actingOptions" />
            @if ($this->isOwner)
                <flux:button size="sm" icon="cog-6-tooth" :href="route('contracts.settings', $contract)" wire:navigate>{{ __('Settings') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($contract->previous_version_id)
        <flux:text size="sm">
            {{ __('Continues') }}
            <flux:link :href="route('contracts.show', $contract->previous_version_id)" wire:navigate>{{ __('version :v', ['v' => $contract->version - 1]) }}</flux:link>.
        </flux:text>
    @endif

    @if ($contract->isAgreed())
        <flux:callout icon="lock-closed" color="green" :heading="__('Agreed on :date', ['date' => $contract->agreed_at->toFormattedDayDateString()])">
            <flux:callout.text>{{ __('This version is locked. Any change needs a new version.') }}</flux:callout.text>
            <x-slot name="actions">
                @if ($this->nextVersion)
                    <flux:button size="sm" :href="route('contracts.show', $this->nextVersion)" wire:navigate>{{ __('Open version :v', ['v' => $this->nextVersion->version]) }}</flux:button>
                @elseif ($this->isOwner)
                    <flux:button size="sm" variant="primary" wire:click="startNewVersion" wire:confirm="{{ __('Start a new version? It copies the current content; approvals and sign-offs start again.') }}">{{ __('Start new version') }}</flux:button>
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

            @if ($this->actingIsRequired && ! $contract->isAgreed())
                <div class="flex items-center gap-2">
                    @if ($this->actingHasSignedOff)
                        <flux:badge color="green" icon="check">{{ __('You signed off') }}</flux:badge>
                        <flux:button size="sm" variant="ghost" wire:click="withdrawSignOff">{{ __('Withdraw') }}</flux:button>
                    @else
                        <flux:button variant="primary" wire:click="signOff" :disabled="! $this->report->isReady()" data-test="sign-off-button">
                            {{ __('Sign off contract') }}
                        </flux:button>
                    @endif
                </div>
            @endif
        </div>

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

    {{-- Phases, steps and the approval matrix --}}
    <div class="space-y-6">
        @forelse ($this->phases as $phase)
            <flux:card class="space-y-3" wire:key="phase-{{ $phase->id }}">
                <div class="flex items-center justify-between gap-3">
                    @if ($renamingPhase === $phase->id)
                        <form wire:submit="renamePhase" class="flex flex-1 items-center gap-2">
                            <flux:input wire:model="phaseTitle" size="sm" class="flex-1" />
                            <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="$set('renamingPhase', null)">{{ __('Cancel') }}</flux:button>
                        </form>
                    @else
                        <flux:heading size="lg" level="2">{{ $phase->title }}</flux:heading>
                        @if ($this->canEdit)
                            <div class="flex items-center gap-1">
                                <flux:button size="xs" variant="ghost" icon="chevron-up" wire:click="movePhase({{ $phase->id }}, -1)" :disabled="$loop->first" :tooltip="__('Move up')" />
                                <flux:button size="xs" variant="ghost" icon="chevron-down" wire:click="movePhase({{ $phase->id }}, 1)" :disabled="$loop->last" :tooltip="__('Move down')" />
                                <flux:button size="xs" variant="ghost" icon="pencil" wire:click="startRenaming({{ $phase->id }})" :tooltip="__('Rename')" />
                            </div>
                        @endif
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 text-left text-zinc-500 dark:border-zinc-700">
                                @if ($this->canEdit)<th class="w-6"></th>@endif
                                <th class="py-2 pe-3 font-medium">{{ __('Step') }}</th>
                                <th class="py-2 pe-3 font-medium">{{ __('Rev') }}</th>
                                @foreach ($this->requiredParties as $party)
                                    <th class="py-2 pe-3 text-center font-medium" title="{{ $party->name }}">{{ $party->name }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody @if ($this->canEdit) wire:sort="sortStep" wire:sort:group="steps" wire:sort:group-id="{{ $phase->id }}" @endif>
                            @foreach ($phase->steps as $step)
                                <tr wire:key="step-{{ $step->id }}" @if ($this->canEdit) wire:sort:item="{{ $step->id }}" @endif class="border-b border-zinc-100 last:border-0 dark:border-zinc-700/60">
                                    @if ($this->canEdit)
                                        <td class="cursor-grab text-zinc-400" wire:sort:handle><flux:icon.bars-3 variant="micro" /></td>
                                    @endif
                                    <td class="py-2 pe-3">
                                        <flux:link :href="route('steps.show', ['contract' => $contract, 'step' => $step])" wire:navigate>{{ $step->currentRevision?->title }}</flux:link>
                                    </td>
                                    <td class="py-2 pe-3 text-zinc-500">{{ $step->currentRevision?->number }}</td>
                                    @foreach ($this->requiredParties as $party)
                                        <td class="py-2 pe-3 text-center">
                                            @if (isset($this->approved[$step->id.':'.$party->id]))
                                                <flux:icon.check-circle variant="mini" class="inline text-green-500" />
                                            @else
                                                <flux:icon.minus-circle variant="mini" class="inline text-zinc-300 dark:text-zinc-600" />
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($phase->steps->isEmpty())
                        <flux:text size="sm" class="py-2">{{ __('No steps in this phase.') }}</flux:text>
                    @endif
                </div>

                @if ($this->canEdit)
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
                        <flux:button size="sm" variant="subtle" icon="plus" wire:click="startAddingStep({{ $phase->id }})">{{ __('Add step') }}</flux:button>
                    @endif
                @endif
            </flux:card>
        @empty
            <flux:text>{{ __('No phases yet.') }}</flux:text>
        @endforelse

        @if ($this->canEdit)
            <form wire:submit="addPhase" class="flex items-end gap-3">
                <flux:input wire:model="newPhaseTitle" :label="__('New phase')" :placeholder="__('Title')" class="flex-1" />
                <flux:button type="submit">{{ __('Add phase') }}</flux:button>
            </form>
        @endif

        @if ($this->archivedCount > 0)
            <flux:text size="sm">{{ trans_choice(':count archived step is hidden.|:count archived steps are hidden.', $this->archivedCount) }}</flux:text>
        @endif
    </div>
</section>
