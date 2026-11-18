<?php

namespace App\Models;

use App\Models\Concerns\HasRandomId;
use Database\Factories\ActivityEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Timeline entry. `seq` (assigned after commit by ActivitySequencer) is the cursor
 * for activity?since=; never page by `id`, which can commit out of order.
 *
 * @property string $id
 * @property string $organisation_id
 * @property string|null $contract_id
 * @property int $position Order within the contract (1, 2, 3...), set by ActivityLog.
 * @property string|null $actor_party_id
 * @property string|null $actor_user_id
 * @property string $type
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property array<string, mixed>|null $payload
 * @property int|null $seq
 * @property Carbon $created_at
 */
#[Fillable(['organisation_id', 'contract_id', 'position', 'actor_party_id', 'actor_user_id', 'type', 'subject_type', 'subject_id', 'payload'])]
class ActivityEvent extends Model
{
    /** @use HasFactory<ActivityEventFactory> */
    use HasFactory, HasRandomId;

    public const UPDATED_AT = null;

    /**
     * Events on contracts the actor can see.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User|Party $actor): void
    {
        $query->whereHas('contract', fn (Builder $contracts) => $contracts->visibleTo($actor));
    }

    /**
     * Sequenced events after a cursor, in commit order.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function after(Builder $query, int $cursor): void
    {
        $query->whereNotNull('seq')->where('seq', '>', $cursor)->orderBy('seq');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function actorParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'actor_party_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
