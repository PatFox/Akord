<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\ChecklistItem;
use App\Models\ChecklistStage;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ChecklistItemOrder;
use App\Support\ContractTransaction;

/**
 * Moves a checklist item within its stage or to another stage of the same checklist, keeping its tick. Same rules as
 * adding an item.
 */
final class MoveChecklistItem
{
    /**
     * @param  string|null  $stageId  null: the item's own stage
     * @param  string|null  $afterItemId  null: end of the stage; ChecklistItemOrder::START: start; otherwise directly after that item
     */
    public function handle(Actor $actor, Contract $contract, string $itemId, ?string $stageId, ?string $afterItemId): ChecklistItem
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($itemId, $stageId, $afterItemId): ChecklistItem {
            ChecklistInput::ensureOpen($contract);
            $item = ChecklistInput::findItem($contract, $itemId);
            $fromStageId = $item->checklist_stage_id;

            $targetId = $stageId ?? $fromStageId;
            if (! ChecklistStage::query()->whereKey($targetId)->where('checklist_id', $item->stage->checklist_id)->exists()) {
                throw new InvalidActionException('stage_id must be a stage of the item\'s own checklist.');
            }

            $position = ChecklistItemOrder::place($item, $targetId, ChecklistItemOrder::positionAfter($targetId, $afterItemId, $item->id));

            if ($fromStageId !== $targetId) {
                ChecklistItemOrder::compact($fromStageId);
            }

            $log->record('checklist.item_moved', $item, ['from_stage_id' => $fromStageId, 'stage_id' => $targetId, 'position' => $position]);

            return $item->refresh();
        }, allowWhenLocked: true);
    }
}
