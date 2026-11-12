<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Models\Contract;
use App\Models\Invitation;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;

/**
 * Owner-only: cancels a pending invitation so its link stops working.
 */
final class CancelInvitation
{
    public function handle(Actor $actor, Invitation $invitation): Invitation
    {
        return ContractTransaction::run($invitation->contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($invitation): Invitation {
            $invitation = Invitation::query()->where('contract_id', $contract->id)->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
                $invitation->update(['revoked_at' => now()]);
                $log->record('invitation.cancelled', $invitation, ['party_id' => $invitation->party_id]);
            }

            return $invitation;
        }, allowWhenAgreed: true);
    }
}
