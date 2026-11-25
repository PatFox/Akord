<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Models\ChecklistItem;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Ticks or unticks one checklist item, recording who and when. Whoever may edit, or a party assigned to the item's
 * checklist whatever its role (D18); works on agreed versions but not on complete or cancelled ones. Repeating the
 * current state is a no-op.
 */
final class SetChecklistItemChecked
{
    public function handle(Actor $actor, Contract $contract, string $itemId, bool $checked): ChecklistItem
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::View, function (Contract $contract, ActivityLog $log) use ($actor, $itemId, $checked): ChecklistItem {
            $item = ChecklistInput::findItem($contract, $itemId);

            ContractAccess::authorizeTick($actor, $contract, $item->stage->checklist);
            ChecklistInput::ensureOpen($contract);

            if ($item->isChecked() === $checked) {
                return $item;
            }

            $item->update($checked
                ? ['checked_at' => now(), 'checked_by_party_id' => ContractAccess::actingParty($actor)->getKey(), 'checked_by_user_id' => $actor->user?->getKey()]
                : ['checked_at' => null, 'checked_by_party_id' => null, 'checked_by_user_id' => null]);

            $log->record($checked ? 'checklist.item_checked' : 'checklist.item_unchecked', $item);

            return $item;
        }, allowWhenLocked: true);
    }
}
