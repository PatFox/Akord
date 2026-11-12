<?php

namespace App\Actions\Contracts;

use App\Casts\NormalisedEmail;
use App\Enums\ContractAbility;
use App\Exceptions\InvalidActionException;
use App\Mail\InvitationMail;
use App\Models\Contract;
use App\Models\Invitation;
use App\Models\Party;
use App\Support\ActivityLog;
use App\Support\Actor;
use App\Support\ContractAccess;
use App\Support\ContractTransaction;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Owner-only (D4): invites a person by email to a party on this contract. Accepting makes them a member of the party, so they see every contract the party is attached to. Allowed on agreed contracts: joining a party does not change the agreed content.
 */
final class InviteToContract
{
    public const VALID_FOR_DAYS = 7;

    /**
     * Invites an email address to join a party attached to the contract, and emails the link.
     * The email is sent after commit; if sending fails the invitation stays pending and
     * the exception propagates, so the owner can resend.
     */
    public function handle(Actor $actor, Contract $contract, Party $party, string $email): Invitation
    {
        $email = NormalisedEmail::normalise($email);

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidActionException('Enter a valid email address.');
        }

        [$invitation, $token] = ContractTransaction::run($contract, $actor, ContractAbility::Administer, function (Contract $contract, ActivityLog $log) use ($actor, $party, $email): array {
            if (ContractAccess::membership($party, $contract) === null) {
                throw new InvalidActionException('Attach the party to the contract before inviting people to it.');
            }

            $party = Party::query()->findOrFail($party->id);
            if ($party->isAgent()) {
                throw new InvalidActionException('Agent parties connect with a claim code, not an invitation.');
            }

            if ($party->users()->where('users.email', $email)->exists()) {
                throw new InvalidActionException('That person is already a member of this party.');
            }

            $alreadyInvited = Invitation::query()->pending()
                ->where('contract_id', $contract->id)->where('party_id', $party->id)->where('email', $email)
                ->exists();
            if ($alreadyInvited) {
                throw new InvalidActionException('That person already has a pending invitation. Resend it instead.');
            }

            $token = Str::random(64);
            $invitation = Invitation::query()->create([
                'organisation_id' => $contract->organisationId(),
                'contract_id' => $contract->id,
                'party_id' => $party->id,
                'email' => $email,
                'token_hash' => Invitation::hashToken($token),
                'expires_at' => now()->addDays(self::VALID_FOR_DAYS),
                'invited_by' => $actor->user?->getKey(),
            ]);

            $log->record('invitation.sent', $invitation, ['party_id' => $party->id]);

            return [$invitation, $token];
        }, allowWhenAgreed: true);

        Mail::to($invitation->email)->send(new InvitationMail($invitation, $token));

        return $invitation;
    }
}
