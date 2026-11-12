<?php

namespace App\Events;

use App\Models\ActivityEvent;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired once per activity row, only after the transaction commits.
 * Broadcasting (Phase 5) and notifications (Phase 8) hang off this.
 */
class ActivityRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public ActivityEvent $activity) {}
}
