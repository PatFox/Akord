<?php

namespace App\Models;

use App\Enums\PartyType;
use Database\Factories\PartyFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property PartyType $type
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organisation_id', 'name', 'type'])]
class Party extends Model implements AuthenticatableContract
{
    // Agent parties authenticate to the API and MCP server with Sanctum tokens
    // (the token's "user" is the party). Parties have no password: they never log in to the web app.
    use Authenticatable, HasApiTokens;

    /** @use HasFactory<PartyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PartyType::class,
        ];
    }

    public function isAgent(): bool
    {
        return $this->type === PartyType::Agent;
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * Human members; agent parties have none.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * @return BelongsToMany<Contract, $this, ContractParty>
     */
    public function contracts(): BelongsToMany
    {
        return $this->belongsToMany(Contract::class)
            ->using(ContractParty::class)
            ->withPivot('role', 'is_required_approver')
            ->withTimestamps();
    }

    /**
     * @return HasMany<AgentClaimCode, $this>
     */
    public function claimCodes(): HasMany
    {
        return $this->hasMany(AgentClaimCode::class);
    }
}
