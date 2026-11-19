<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\ChecklistItem;
use App\Models\ChecklistStage;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Adds an item at the end of a checklist stage, for work found along the way. Same rules as creating the checklist.
 */
final class AddChecklistItem
{
    public function handle(Actor $actor, Contract $contract, string $stageId, string $title, ?string $notes = null, ?string $stepId = null): ChecklistItem
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($stageId, $title, $notes, $stepId): ChecklistItem {
            ChecklistInput::ensureOpen($contract);

            $stage = ChecklistStage::query()->whereKey($stageId)
                ->whereRelation('checklist', 'contract_id', $contract->getKey())
                ->firstOrFail();

            $count = ChecklistItem::query()->whereRelation('stage', 'checklist_id', $stage->checklist_id)->count();
            if ($count >= ChecklistInput::MAX_ITEMS) {
                throw new InvalidActionException('A checklist can have at most '.ChecklistInput::MAX_ITEMS.' items.');
            }

            $item = ChecklistItem::query()->create([
                'checklist_stage_id' => $stage->id,
                'position' => (int) ChecklistItem::query()->where('checklist_stage_id', $stage->id)->max('position') + 1,
                'title' => $title,
                'notes' => $notes ?: null,
                'step_id' => ChecklistInput::stepId($contract, $stepId),
            ]);

            $log->record('checklist.item_added', $item, ['stage_id' => $stage->id]);

            return $item;
        }, allowWhenLocked: true);
    }
}
