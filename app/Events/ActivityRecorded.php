<?php

namespace App\Events;

use App\Models\ActivityEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per activity row, only after the transaction commits, and broadcast
 * (queued) on the contract's private channel as "activity". The payload carries ids
 * and the type only; clients reload what they show through their normal access checks.
 * Notifications (Phase 8) hang off this too.
 */
class ActivityRecorded implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public ActivityEvent $activity) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return $this->activity->contract_id === null ? [] : [new PrivateChannel('contract.'.$this->activity->contract_id)];
    }

    public function broadcastAs(): string
    {
        return 'activity';
    }

    /**
     * @return array{id: int, type: string, contract_id: int|null, subject_type: string|null, subject_id: int|null}
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->activity->id,
            'type' => $this->activity->type,
            'contract_id' => $this->activity->contract_id,
            'subject_type' => $this->activity->subject_type,
            'subject_id' => $this->activity->subject_id,
        ];
    }
}
