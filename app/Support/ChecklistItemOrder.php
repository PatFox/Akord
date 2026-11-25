<?php

namespace App\Support;

use App\Exceptions\InvalidActionException;
use App\Models\ChecklistItem;

/**
 * Order of a checklist stage's items (positions 1..n), like StepOrder for steps. Call only inside a ContractTransaction.
 */
final class ChecklistItemOrder
{
    /** The `after_item_id` sentinel for "the start of the stage". */
    public const START = 'start';

    /**
     * The 1-based position for "after this item": null is the end of the stage, ChecklistItemOrder::START the start,
     * otherwise directly after that item of the stage.
     *
     * @throws InvalidActionException the anchor is the item itself, or not an item of the stage (422)
     */
    public static function positionAfter(string $stageId, ?string $afterItemId, string $excludingItemId): int
    {
        $siblings = self::ids($stageId, $excludingItemId);

        if ($afterItemId === null) {
            return count($siblings) + 1;
        }

        if ($afterItemId === self::START) {
            return 1;
        }

        if ($afterItemId === $excludingItemId) {
            throw new InvalidActionException('An item cannot be placed after itself.');
        }

        $index = array_search($afterItemId, $siblings, true);

        if ($index === false) {
            throw new InvalidActionException("Item {$afterItemId} is not an item of stage {$stageId}.");
        }

        return $index + 2;
    }

    /**
     * Puts the item into the stage at the position (clamped to the ends) and renumbers the stage.
     */
    public static function place(ChecklistItem $item, string $stageId, int $position): int
    {
        $siblings = self::ids($stageId, $item->id);
        $position = max(1, min($position, count($siblings) + 1));
        array_splice($siblings, $position - 1, 0, [$item->id]);

        if ($item->checklist_stage_id !== $stageId) {
            $item->update(['checklist_stage_id' => $stageId]);
        }

        self::renumber($siblings);

        return $position;
    }

    /**
     * Closes the gaps in a stage's items (after an item left it).
     */
    public static function compact(string $stageId): void
    {
        self::renumber(self::ids($stageId));
    }

    /**
     * @return list<string>
     */
    private static function ids(string $stageId, ?string $excludingItemId = null): array
    {
        return array_values(ChecklistItem::query()->where('checklist_stage_id', $stageId)
            ->when($excludingItemId !== null, fn ($query) => $query->whereKeyNot($excludingItemId))
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->all());
    }

    /**
     * @param  list<string>  $itemIds
     */
    private static function renumber(array $itemIds): void
    {
        foreach ($itemIds as $index => $id) {
            ChecklistItem::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }
}
