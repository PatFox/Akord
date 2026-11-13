<?php

namespace App\Support;

use App\Exceptions\InvalidActionException;
use App\Models\Phase;
use App\Models\Step;

/**
 * Order of a phase's active steps (positions 1..n). Call only inside a ContractTransaction.
 */
final class StepOrder
{
    /**
     * The 1-based position for "after this step": null is the end of the phase, 0 the start,
     * otherwise directly after that active step of the phase.
     *
     * @param  int|null  $excludingStepId  the step being placed, when it is already in the phase
     *
     * @throws InvalidActionException the anchor is the step itself, or not an active step of the phase (422)
     */
    public static function positionAfter(Phase $phase, ?int $afterStepId, ?int $excludingStepId = null): int
    {
        $siblings = self::activeIds($phase->id, $excludingStepId);

        if ($afterStepId === null) {
            return count($siblings) + 1;
        }

        if ($afterStepId === 0) {
            return 1;
        }

        if ($afterStepId === $excludingStepId) {
            throw new InvalidActionException('A step cannot be placed after itself.');
        }

        $index = array_search($afterStepId, $siblings, true);

        if ($index === false) {
            throw new InvalidActionException("Step {$afterStepId} is not an active step of phase {$phase->id}.");
        }

        return $index + 2;
    }

    /**
     * Puts the step into the phase at the position (clamped to the ends) and renumbers the phase.
     */
    public static function place(Phase $phase, Step $step, int $position): int
    {
        $siblings = self::activeIds($phase->id, $step->id);
        $position = max(1, min($position, count($siblings) + 1));
        array_splice($siblings, $position - 1, 0, [$step->id]);

        if ($step->phase_id !== $phase->id) {
            $step->update(['phase_id' => $phase->id]);
        }

        self::renumber($siblings);

        return $position;
    }

    /**
     * Closes the gaps in a phase's active steps (after a step left it).
     */
    public static function compact(int $phaseId): void
    {
        self::renumber(self::activeIds($phaseId));
    }

    /**
     * @return list<int>
     */
    private static function activeIds(int $phaseId, ?int $excludingStepId = null): array
    {
        return array_values(Step::query()->where('phase_id', $phaseId)->active()
            ->when($excludingStepId !== null, fn ($query) => $query->whereKeyNot($excludingStepId))
            ->orderBy('position')->orderBy('id')
            ->pluck('id')->map(intval(...))->all());
    }

    /**
     * @param  list<int>  $stepIds
     */
    private static function renumber(array $stepIds): void
    {
        foreach ($stepIds as $index => $id) {
            Step::query()->whereKey($id)->update(['position' => $index + 1]);
        }
    }
}
