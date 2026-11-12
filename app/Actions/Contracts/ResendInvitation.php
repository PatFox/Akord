<?php

namespace App\Actions\Contracts;

use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Mail\InvitationMail;
use App\Models\Contract;
use App\Models\Invitation;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractTransaction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Owner-only: re-sends an invitation with a new link.
 */
final class ResendInvitation
{
    /**
     * Issues a fresh link (new token, new expiry) and emails it. The previous link stops working.
     */
    public function handle(Actor $actor, Invitation $invitation): Invitation
    {
        [$invitation, $token] = ContractTransaction::run($invitation->contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($invitation): array {
            $invitation = Invitation::query()->where('contract_id', $contract->id)->lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                throw new InvalidActionException('Only pending or expired invitations can be resent.');
            }

            $token = Str::random(64);
            $invitation->update([
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(InviteToContract::VALID_FOR_DAYS),
            ]);

            $log->record('invitation.resent', $invitation, ['party_id' => $invitation->party_id]);

            return [$invitation, $token];
        }, allowWhenAgreed: true);

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $token));

        return $invitation;
    }
}
