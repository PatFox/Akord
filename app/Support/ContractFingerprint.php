<?php

namespace App\Support;

/**
 * The contract state a sign-off refers to: the set of (active step, content hash)
 * pairs plus the set of required parties. Order-insensitive. Stored in sign-offs, so
 * changing this algorithm invalidates every existing sign-off; tests pin it.
 */
final class ContractFingerprint
{
    /**
     * @param  array<int|string, string>  $contentHashByStepId
     * @param  array<int, int|string>  $requiredPartyIds
     */
    public static function compute(array $contentHashByStepId, array $requiredPartyIds): string
    {
        $steps = [];
        foreach ($contentHashByStepId as $stepId => $hash) {
            $steps[] = [(int) $stepId, $hash];
        }
        usort($steps, fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $required = array_map(intval(...), array_values($requiredPartyIds));
        sort($required);

        return hash('sha256', (string) json_encode(['steps' => $steps, 'required' => $required]));
    }
}
