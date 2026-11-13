<?php

namespace App\Listeners;

use App\Events\ActivityRecorded;
use App\Models\ActivityEvent;
use App\Models\Contract;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\ContractActivity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Decides who hears about an activity row. Runs on the queue after the change commits,
 * so a slow or failing mail server never affects the change itself.
 *
 * Recipients are people in parties attached to the contract, never the person who made
 * the change, and never agents (they poll the activity feed, Q6). Owners who are not in
 * an attached party are not told, except about invitations they sent.
 */
class SendActivityNotifications implements ShouldQueue
{
    public function handle(ActivityRecorded $event): void
    {
        $activity = $event->activity;
        $contract = $activity->contract;

        if ($contract === null) {
            return;
        }

        $payload = $activity->payload ?? [];

        $recipients = match ($activity->type) {
            'comment.added', 'contract.agreed' => $this->members($contract),
            'step.revised' => ($payload['content_changed'] ?? true) ? $this->members($contract) : null,
            'contract.ready' => $this->members($contract, $this->ids($contract->requiredApprovers()->pluck('parties.id')->all())),
            'signoffs.invalidated' => $this->members($contract, $this->ids($payload['party_ids'] ?? [])),
            'invitation.accepted' => $this->inviter($payload),
            default => null,
        };

        if ($recipients === null) {
            return;
        }

        $recipients = $recipients->reject(fn (User $user): bool => $user->id === $activity->actor_user_id);

        if ($activity->type === 'step.revised') {
            $this->sendRevision($activity, $contract, $recipients, $this->ids($payload['cancelled_party_ids'] ?? []));

            return;
        }

        Notification::send($recipients, new ContractActivity($activity));
    }

    /**
     * Everyone is told about the edit; members of parties whose approval it cancelled are also told that.
     *
     * @param  Collection<int, User>  $recipients
     * @param  list<int>  $cancelledPartyIds
     */
    private function sendRevision(ActivityEvent $activity, Contract $contract, Collection $recipients, array $cancelledPartyIds): void
    {
        $cancelledUserIds = $cancelledPartyIds === [] ? [] : $this->members($contract, $cancelledPartyIds)->modelKeys();
        [$cancelled, $others] = $recipients->partition(fn (User $user): bool => in_array($user->id, $cancelledUserIds, true));

        Notification::send($cancelled, new ContractActivity($activity, approvalCancelled: true));
        Notification::send($others, new ContractActivity($activity));
    }

    /**
     * People in the contract's attached parties, optionally only some of those parties.
     *
     * @param  list<int>|null  $partyIds
     * @return Collection<int, User>
     */
    private function members(Contract $contract, ?array $partyIds = null): Collection
    {
        $attached = $this->ids($contract->parties()->pluck('parties.id')->all());
        $partyIds = $partyIds === null ? $attached : array_values(array_intersect($partyIds, $attached));

        return User::query()
            ->whereHas('parties', fn (Builder $parties) => $parties->whereIn('parties.id', $partyIds))
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, User>
     */
    private function inviter(array $payload): Collection
    {
        $inviterId = Invitation::query()->whereKey($payload['invitation_id'] ?? 0)->value('invited_by');

        return User::query()->whereKey($inviterId ?? 0)->get();
    }

    /**
     * @return list<int>
     */
    private function ids(mixed $ids): array
    {
        return is_array($ids) ? array_values(array_map(intval(...), $ids)) : [];
    }
}
