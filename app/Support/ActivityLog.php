<?php

namespace App\Support;

use App\Events\ActivityRecorded;
use App\Models\ActivityEvent;
use App\Models\Contract;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes activity rows for one contract while its row is locked (inside a ContractTransaction,
 * or a transaction that locked the contract first). Each event gets the contract's next
 * `position`, so its events keep the order they happened in; ids are random.
 */
final class ActivityLog
{
    private ?int $lastPosition = null;

    public function __construct(
        private readonly Contract $contract,
        private readonly Actor $actor,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $type, ?Model $subject = null, array $payload = []): ActivityEvent
    {
        $event = new ActivityEvent([
            'organisation_id' => $this->contract->organisationId(),
            'contract_id' => $this->contract->getKey(),
            'position' => $this->nextPosition(),
            'actor_party_id' => $this->actor->party?->getKey(),
            'actor_user_id' => $this->actor->user?->getKey(),
            'type' => $type,
            'payload' => $payload,
        ]);

        if ($subject !== null) {
            $event->subject()->associate($subject);
        }

        $event->save();

        event(new ActivityRecorded($event));

        return $event;
    }

    public function readinessChanged(ReadinessReport $before, ReadinessReport $after): void
    {
        if ($before->signedOffPartyIds !== [] && $before->fingerprint !== $after->fingerprint) {
            $this->record('signoffs.invalidated', null, [
                'party_ids' => $before->signedOffPartyIds,
                'fingerprint' => $before->fingerprint,
            ]);
        }

        if (! $before->isReady() && $after->isReady()) {
            $this->record('contract.ready', null, ['fingerprint' => $after->fingerprint]);
        } elseif ($before->isReady() && ! $after->isReady()) {
            $this->record('contract.no_longer_ready');
        }
    }

    /**
     * Safe without a lock of its own: the caller holds the contract row lock, so only one
     * transaction at a time writes this contract's events. The unique (contract_id, position)
     * index turns a caller that forgot the lock into an error instead of a wrong order.
     */
    private function nextPosition(): int
    {
        $this->lastPosition ??= (int) ActivityEvent::query()
            ->where('contract_id', $this->contract->getKey())
            ->max('position');

        return ++$this->lastPosition;
    }
}
