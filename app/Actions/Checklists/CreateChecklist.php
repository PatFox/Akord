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
 * it came from. Without stages, it copies the contract's phases and active steps. A version can have several, each
 * named; creating one with an existing name (ignoring case) replaces it, which needs $replace if items are ticked.
 * Needs the edit ability; works on agreed versions (that is when the work happens) but not on complete or cancelled
 * ones.
 */
final class CreateChecklist
{
    /**
     * @param  list<array{title: string, items: list<array{title: string, notes?: string|null, step_id?: string|null}>}>|null  $stages
     */
    public function handle(Actor $actor, Contract $contract, string $name, ?array $stages = null, bool $replace = false): Checklist
    {
        $name = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidActionException('A checklist needs a name of up to 60 characters.');
        }

        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($actor, $name, $stages, $replace): Checklist {
            ChecklistInput::ensureOpen($contract);

            $stages ??= $this->fromContract($contract);
            $itemCount = array_sum(array_map(fn (array $stage) => count($stage['items']), $stages));
            if ($itemCount > ChecklistInput::MAX_ITEMS) {
                throw new InvalidActionException('A checklist can have at most '.ChecklistInput::MAX_ITEMS.' items.');
            }

            // The same name, ignoring case (MySQL compares case-insensitively, SQLite does not), is the same checklist.
            $existing = Checklist::query()->where('contract_id', $contract->getKey())->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
            if ($existing !== null) {
                if (! $replace && $existing->items()->whereNotNull('checked_at')->exists()) {
                    throw new InvalidActionException("The checklist \"{$existing->name}\" has ticked items. Pass replace to start it again.");
                }
                $existing->delete();
            } elseif (Checklist::query()->where('contract_id', $contract->getKey())->count() >= ChecklistInput::MAX_CHECKLISTS) {
                throw new InvalidActionException('A contract can have at most '.ChecklistInput::MAX_CHECKLISTS.' checklists.');
            }

            $checklist = Checklist::query()->create([
                'contract_id' => $contract->getKey(),
                'name' => $name,
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

            $log->record('checklist.created', $checklist, ['name' => $name, 'stages' => count($stages), 'items' => $itemCount, 'replaced' => $existing !== null]);

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
