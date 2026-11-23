<?php

namespace App\Actions\Agents;

use App\Exceptions\ClaimCodeUnavailableException;
use App\Models\AgentConnectionLink;
use App\Models\Party;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Uses up a connection link and returns its agent party. Each link works once (the row is
 * locked, so two simultaneous uses cannot both succeed) and expires.
 *
 * Errors: unknown link 404; used or expired 410.
 */
final class UseAgentConnectionLink
{
    public function handle(string $token): Party
    {
        return DB::transaction(function () use ($token): Party {
            $link = AgentConnectionLink::query()
                ->where('token_hash', AgentConnectionLink::hashToken($token))
                ->lockForUpdate()
                ->first()
                ?? throw (new ModelNotFoundException)->setModel(AgentConnectionLink::class);

            if (! $link->isUsable()) {
                throw new ClaimCodeUnavailableException($link->unusableReason());
            }

            $link->update(['used_at' => now()]);

            return Party::query()->findOrFail($link->party_id);
        });
    }
}
