<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Numbers committed activity events in commit order (`seq`, the activity cursor).
 *
 * Auto-increment ids are allocated at insert, not commit, so a reader paging by id
 * could skip a lower id that commits late. This runs in its own short transaction
 * behind the single activity_sequencer row lock: it only sees committed events, and
 * every run commits before the next starts, so a late committer always receives a
 * higher seq than anything a reader has already seen. Runs after every contract
 * write and should run before serving the feed.
 */
final class ActivitySequencer
{
    public static function run(): void
    {
        DB::transaction(function (): void {
            // Locking read first: on MySQL REPEATABLE READ the snapshot for the plain read
            // below is then taken after the lock, so it sees every committed event.
            $last = (int) DB::table('activity_sequencer')->where('id', 1)->lockForUpdate()->value('last_seq');

            $pending = DB::table('activity_events')->whereNull('seq')->orderBy('id')->pluck('id');

            if ($pending->isEmpty()) {
                return;
            }

            foreach ($pending as $id) {
                DB::table('activity_events')->where('id', $id)->update(['seq' => ++$last]);
            }

            DB::table('activity_sequencer')->where('id', 1)->update(['last_seq' => $last]);
        }, 3);
    }
}
