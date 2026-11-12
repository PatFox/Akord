<?php

namespace App\Models;

use Database\Factories\AgentClaimCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time code an agent exchanges for an API token.
 *
 * @property int $id
 * @property int $party_id
 * @property string $code_hash
 * @property list<string>|null $abilities
 * @property int|null $created_by
 * @property Carbon $expires_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['party_id', 'code_hash', 'abilities', 'created_by', 'expires_at', 'claimed_at'])]
#[Hidden(['code_hash'])]
class AgentClaimCode extends Model
{
    /** @use HasFactory<AgentClaimCodeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    /**
     * Codes are shown as AKD-XXXXX-XXXXX-XXXXX-XXXXX; case, spaces and dashes don't matter.
     */
    public static function hashCode(string $code): string
    {
        $normalised = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));

        return hash('sha256', $normalised);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
