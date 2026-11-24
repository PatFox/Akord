<?php

namespace App\Models;

use App\Models\Concerns\HasRandomId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Carbon;

/**
 * One of a contract version's working checklists (one per team, say), written by an agent from the plan. Names are
 * unique within a version, ignoring case. Its assigned parties tick its items whatever their role (D18).
 *
 * @property string $id
 * @property string $contract_id
 * @property string $name
 * @property string $created_by_party_id
 * @property string|null $created_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['contract_id', 'name', 'created_by_party_id', 'created_by_user_id'])]
class Checklist extends Model
{
    use HasRandomId;

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return HasMany<ChecklistStage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(ChecklistStage::class)->orderBy('position');
    }

    /**
     * @return HasManyThrough<ChecklistItem, ChecklistStage, $this>
     */
    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(ChecklistItem::class, ChecklistStage::class);
    }

    /**
     * Parties that tick this checklist's items whatever their role on the contract (D18); owners assign them.
     *
     * @return BelongsToMany<Party, $this>
     */
    public function assignedParties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class)->withTimestamps();
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function createdByParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'created_by_party_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
