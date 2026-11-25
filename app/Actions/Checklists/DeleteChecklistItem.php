<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ChecklistItemOrder;
use App\Support\ContractTransaction;

/**
 * Deletes a checklist item, ticked or not, and closes the gap in its stage. Same rules as adding an item. The activity
 * row keeps the item's title and checklist name in its payload, since the item is gone.
 */
final class DeleteChecklistItem
{
    public function handle(Actor $actor, Contract $contract, string $itemId): void
    {
        ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($itemId): void {
            ChecklistInput::ensureOpen($contract);
            $item = ChecklistInput::findItem($contract, $itemId);
            $checklist = $item->stage->checklist;

            $item->delete();
            ChecklistItemOrder::compact($item->checklist_stage_id);

            $log->record('checklist.item_deleted', null, [
                'item_id' => $item->id,
                'title' => $item->title,
                'stage_id' => $item->checklist_stage_id,
                'checklist_id' => $checklist->id,
                'name' => $checklist->name,
                'was_checked' => $item->isChecked(),
            ]);
        }, allowWhenLocked: true);
    }
}
