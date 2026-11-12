<?php

namespace App\Actions\Approvals;

use App\Enums\ContractAbility;
use App\Models\Approval;
use App\Models\Contract;
use App\Models\Step;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Withdraws the actor's party's active approvals of a step.
 */
final class WithdrawApproval
{
    /**
     * @return int the number of approvals withdrawn
     */
    public function handle(Actor $actor, Step $step): int
    {
        return ContractTransaction::run($step->phase->contract, $actor, ContractAbility::Approve, function (Contract $contract, ActivityLog $log) use ($actor, $step): int {
            $step = $contract->findStep($step->id);

            $withdrawn = Approval::query()
                ->active()
                ->where('step_id', $step->id)
                ->where('party_id', ContractAccess::actingParty($actor)->getKey())
                ->update(['withdrawn_at' => now()]);

            if ($withdrawn > 0) {
                $log->record('approval.withdrawn', $step, ['step_id' => $step->id]);
            }

            return $withdrawn;
        });
    }
}
