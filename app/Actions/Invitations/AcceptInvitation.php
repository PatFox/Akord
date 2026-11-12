<?php

namespace App\Actions\Invitations;

use App\Casts\NormalisedEmail;
use App\Exceptions\InvitationUnavailableException;
use App\Models\Contract;
use App\Models\Invitation;
use App\Models\Party;
use App\Models\User;
use App\Support\ActivityLog;
use App\Support\ActivitySequencer;
use App\Support\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Accepts an invitation link: the signed-in person must have the invited (normalised)
 * email address; they join the party and so gain access to the contract.
 *
 * Errors: unknown link 404; used, cancelled or expired 410; wrong account 403.
 */
final class AcceptInvitation
{
    public function handle(User $user, string $token): Invitation
    {
        $found = Invitation::findByToken($token)
            ?? throw (new ModelNotFoundException)->setModel(Invitation::class);

        return DB::transaction(function () use ($user, $found): Invitation {
            // Same lock order as every contract write: the contract row first.
            $contract = Contract::query()->lockForUpdate()->whereKey($found->contract_id)->firstOrFail();
            $invitation = Invitation::query()->lockForUpdate()->findOrFail($found->id);

            match ($invitation->status()) {
                'accepted' => throw new InvitationUnavailableException('This invitation has already been used.'),
                'cancelled' => throw new InvitationUnavailableException('This invitation was cancelled.'),
                'expired' => throw new InvitationUnavailableException('This invitation has expired. Ask the person who invited you to resend it.'),
                default => null,
            };

            if (NormalisedEmail::normalise($user->email) !== $invitation->email) {
                throw new AuthorizationException("This invitation is for {$invitation->email}. Sign in with that email address to accept it.");
            }

            $party = Party::query()->findOrFail($invitation->party_id);
            $party->users()->syncWithoutDetaching([$user->id]);
            $invitation->update(['accepted_at' => now(), 'accepted_user_id' => $user->id]);

            (new ActivityLog($contract, Actor::user($user, $party)))->record('invitation.accepted', $party, ['invitation_id' => $invitation->id]);
            DB::afterCommit(ActivitySequencer::run(...));

            return $invitation;
        }, 3);
    }
}
