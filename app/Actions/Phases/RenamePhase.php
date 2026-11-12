<?php

namespace App\Actions\Phases;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Models\Phase;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Renames a phase. Does not affect approvals or sign-offs.
 */
final class RenamePhase
{
    public function handle(Actor $actor, Phase $phase, string $title): Phase
    {
        return ContractTransaction::run($phase->contract, $actor, ContractAbility::Edit, function (Contract $contract, ActivityLog $log) use ($phase, $title): Phase {
            $phase = $contract->findPhase($phase->id);
            $from = $phase->title;

            if ($from !== $title) {
                $phase->update(['title' => $title]);
                $log->record('phase.renamed', $phase, ['from' => $from, 'to' => $title]);
            }

            return $phase;
        });
    }
}
