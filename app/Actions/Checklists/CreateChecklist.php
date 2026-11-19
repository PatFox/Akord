<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Checklist;
use App\Models\ChecklistItem;
use App\Models\ChecklistStage;
use App\Models\Contract;
use App\Models\Phase;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Creates (or replaces) a contract version's checklist: stages of items, each optionally linked to the contract step
 * it came from. Without stages, it copies the contract's phases and active steps. Needs the edit ability; works on
 * agreed versions (that is when the work happens) but not on complete or cancelled ones. Replacing a checklist with
 * ticked items needs $replace.
 */
final class CreateChecklist
{
    /**
     * @param  list<array{title: string, items: list<array{title: string, notes?: string|null, step_id?: string|null}>}>|null  $stages
     */
    public function handle(Actor $actor, Contract $contract, ?array $stages = null, bool $replace = false): Checklist
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($actor, $stages, $replace): Checklist {
            ChecklistInput::ensureOpen($contract);

            $stages ??= $this->fromContract($contract);
            $itemCount = array_sum(array_map(fn (array $stage) => count($stage['items']), $stages));
            if ($itemCount > ChecklistInput::MAX_ITEMS) {
                throw new InvalidActionException('A checklist can have at most '.ChecklistInput::MAX_ITEMS.' items.');
            }

            $existing = Checklist::query()->where('contract_id', $contract->getKey())->first();
            if ($existing !== null) {
                if (! $replace && $existing->items()->whereNotNull('checked_at')->exists()) {
                    throw new InvalidActionException('This contract already has a checklist with ticked items. Pass replace to start a new one.');
                }
                $existing->delete();
            }

            $checklist = Checklist::query()->create([
                'contract_id' => $contract->getKey(),
                'created_by_party_id' => ContractAccess::actingParty($actor)->getKey(),
                'created_by_user_id' => $actor->user?->getKey(),
            ]);

            foreach ($stages as $s => $stage) {
                $row = ChecklistStage::query()->create(['checklist_id' => $checklist->id, 'title' => $stage['title'], 'position' => $s + 1]);
                foreach ($stage['items'] as $i => $item) {
                    ChecklistItem::query()->create([
                        'checklist_stage_id' => $row->id,
                        'position' => $i + 1,
                        'title' => $item['title'],
                        'notes' => ($item['notes'] ?? null) ?: null,
                        'step_id' => ChecklistInput::stepId($contract, $item['step_id'] ?? null),
                    ]);
                }
            }

            $log->record('checklist.created', $checklist, ['stages' => count($stages), 'items' => $itemCount, 'replaced' => $existing !== null]);

            return $checklist;
        }, allowWhenLocked: true);
    }

    /**
     * One stage per phase, one item per active step, linked to it.
     *
     * @return list<array{title: string, items: list<array{title: string, step_id: string}>}>
     */
    private function fromContract(Contract $contract): array
    {
        $stages = [];
        $phases = Phase::query()->where('contract_id', $contract->getKey())->orderBy('position')
            ->with(['steps' => fn ($steps) => $steps->active()->with('currentRevision')])
            ->get();
        foreach ($phases as $phase) {
            $items = [];
            foreach ($phase->steps as $step) {
                $items[] = ['title' => (string) $step->currentRevision?->title, 'step_id' => $step->id];
            }
            $stages[] = ['title' => $phase->title, 'items' => $items];
        }

        return $stages;
    }
}
