<?php

namespace App\Actions\Checklists;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Models\Checklist;
use App\Models\Contract;
use App\Models\Party;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;

/**
 * Owner-only (D4, D18): assigns a party attached to the contract to one of its checklists, so that it ticks the
 * checklist's items whatever its role, or removes the assignment. Like the checklist itself, this works on agreed
 * versions but not on complete or cancelled ones. Repeating the current state is a no-op.
 */
final class SetChecklistAssignee
{
    public function handle(Actor $actor, Contract $contract, string $checklistId, Party $party, bool $assigned): Checklist
    {
        return ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($checklistId, $party, $assigned): Checklist {
            ChecklistInput::ensureOpen($contract);

            $checklist = Checklist::query()->where('contract_id', $contract->getKey())->findOrFail($checklistId);
            // A party from another organisation is reported as missing, not forbidden.
            $party = Party::query()->whereKey($party->id)->where('organisation_id', $contract->organisationId())->firstOrFail();

            if ($assigned && ContractAccess::membership($party, $contract) === null) {
                throw new InvalidActionException('Attach the party to the contract before assigning it to a checklist.');
            }

            $isAssigned = $checklist->assignedParties()->whereKey($party->getKey())->exists();
            if ($isAssigned === $assigned) {
                return $checklist;
            }

            if ($assigned) {
                $checklist->assignedParties()->attach($party);
            } else {
                $checklist->assignedParties()->detach($party);
            }

            $log->record($assigned ? 'checklist.party_assigned' : 'checklist.party_unassigned', $party, ['checklist_id' => $checklist->id, 'name' => $checklist->name]);

            return $checklist;
        }, allowWhenLocked: true);
    }
}
