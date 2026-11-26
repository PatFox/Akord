<?php

namespace App\Actions\Phases;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Contract;
use App\Models\Phase;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;
use App\Support\StepOrder;

/**
 * Reorders a contract's phases. Does not affect approvals or sign-offs.
 */
final class ReorderPhases
{
    /**
     * @param  list<string>  $phaseIds  every phase of the contract, in the new order
     */
    public function handle(Actor $actor, Contract $contract, array $phaseIds): void
    {
        ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($phaseIds): void {
            $existing = $this->orderedIds($contract);
            sort($existing, SORT_STRING);
            $requested = $phaseIds;
            sort($requested, SORT_STRING);

            if ($existing !== $requested) {
                throw new InvalidActionException('The new order must list every phase of the contract exactly once.');
            }

            $this->apply($log, $phaseIds);
        });
    }

    /**
     * Moves one phase of the contract.
     *
     * @param  string|null  $afterPhaseId  null: the end; StepOrder::START: the start; otherwise directly after that phase
     *
     * @throws InvalidActionException the anchor is the phase itself or not a phase of the contract (422)
     */
    public function handleAfter(Actor $actor, Contract $contract, string $phaseId, ?string $afterPhaseId): Phase
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($phaseId, $afterPhaseId): Phase {
            $phase = $contract->findPhase($phaseId);
            $others = array_values(array_diff($this->orderedIds($contract), [$phase->id]));

            if ($afterPhaseId === null) {
                $index = count($others);
            } elseif ($afterPhaseId === StepOrder::START) {
                $index = 0;
            } elseif ($afterPhaseId === $phase->id) {
                throw new InvalidActionException('A phase cannot be placed after itself.');
            } else {
                $anchor = array_search($afterPhaseId, $others, true);
                if ($anchor === false) {
                    throw new InvalidActionException("Phase {$afterPhaseId} is not a phase of this contract.");
                }
                $index = $anchor + 1;
            }

            array_splice($others, $index, 0, [$phase->id]);
            $this->apply($log, $others);

            return $phase->refresh();
        });
    }

    /**
     * @return list<string>
     */
    private function orderedIds(Contract $contract): array
    {
        return array_values(Phase::query()->where('contract_id', $contract->getKey())->orderBy('position')->orderBy('id')->pluck('id')->all());
    }

    /**
     * @param  list<string>  $phaseIds
     */
    private function apply(ActivityLog $log, array $phaseIds): void
    {
        foreach ($phaseIds as $index => $phaseId) {
            Phase::query()->whereKey($phaseId)->update(['position' => $index + 1]);
        }

        $log->record('phases.reordered', null, ['phase_ids' => $phaseIds]);
    }
}
