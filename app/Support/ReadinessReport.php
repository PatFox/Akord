<?php

namespace App\Support;

use App\Enums\ContractState;

final class ReadinessReport
{
    /**
     * @param  list<int>  $requiredPartyIds
     * @param  list<array{step_id: int, party_id: int}>  $outstanding  missing (step, required party) approvals
     * @param  list<int>  $signedOffPartyIds  required parties signed off on the current fingerprint
     */
    public function __construct(
        public readonly ContractState $state,
        public readonly string $fingerprint,
        public readonly int $activeStepCount,
        public readonly array $requiredPartyIds,
        public readonly array $outstanding,
        public readonly array $signedOffPartyIds,
    ) {}

    public function isReady(): bool
    {
        return $this->state === ContractState::ReadyForSignoff;
    }
}
