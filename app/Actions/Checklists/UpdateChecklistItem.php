<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\ChecklistItem;
use App\Models\Contract;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Changes a checklist item's title, notes or linked step. Only the keys given change (empty notes or step_id clear
 * them), and the tick stays. Same rules as adding an item; giving only what the item already has is a no-op.
 */
final class UpdateChecklistItem
{
    public const FIELDS = ['title', 'notes', 'step_id'];

    /**
     * @param  array{title?: string, notes?: string|null, step_id?: string|null}  $changes
     */
    public function handle(Actor $actor, Contract $contract, string $itemId, array $changes): ChecklistItem
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($itemId, $changes): ChecklistItem {
            ChecklistInput::ensureOpen($contract);
            $item = ChecklistInput::findItem($contract, $itemId);

            if (array_intersect_key($changes, array_flip(self::FIELDS)) === []) {
                throw new InvalidActionException('Give a title, notes or step_id to change.');
            }

            if (array_key_exists('title', $changes)) {
                $item->title = $changes['title'];
            }
            if (array_key_exists('notes', $changes)) {
                $item->notes = $changes['notes'] ?: null;
            }
            if (array_key_exists('step_id', $changes)) {
                $item->step_id = ChecklistInput::stepId($contract, $changes['step_id']);
            }

            $fields = array_keys($item->getDirty());
            if ($fields === []) {
                return $item;
            }

            $item->save();
            $log->record('checklist.item_updated', $item, ['fields' => $fields]);

            return $item;
        }, allowWhenLocked: true);
    }
}
