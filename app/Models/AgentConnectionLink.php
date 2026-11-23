<?php

namespace App\Models;

use App\Models\Concerns\HasRandomId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time link an owner gives an agent: the agent fetches its party's MCP configuration
 * with it (App\Http\Controllers\Agents\ConnectionLinkController). Only the hash is stored.
 *
 * @property string $id
 * @property string $party_id
 * @property string $token_hash
 * @property string|null $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['party_id', 'token_hash', 'created_by', 'expires_at', 'used_at'])]
#[Hidden(['token_hash'])]
class AgentConnectionLink extends Model
{
    use HasRandomId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * Why the link can no longer be used, for the agent and the person who gave it the link.
     */
    public function unusableReason(): string
    {
        return $this->used_at !== null
            ? 'This connection link has already been used. Ask an owner for a new one on the agent party’s Agent access page in Akord.'
            : 'This connection link has expired. Ask an owner for a new one on the agent party’s Agent access page in Akord.';
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
