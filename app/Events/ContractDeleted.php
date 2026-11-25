<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A contract was deleted (D19): told, as "deleted", on the private channel of each of its versions, so pages still
 * open on one show that it is gone. Ids only, since the rows no longer exist; sent to others than the deleting tab.
 */
class ContractDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  list<string>  $contractIds  every version that was deleted
     */
    public function __construct(public array $contractIds) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(fn (string $id) => new PrivateChannel('contract.'.$id), $this->contractIds);
    }

    public function broadcastAs(): string
    {
        return 'deleted';
    }

    /**
     * @return array{contract_ids: list<string>}
     */
    public function broadcastWith(): array
    {
        return ['contract_ids' => $this->contractIds];
    }
}
