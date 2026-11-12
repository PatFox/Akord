<?php

namespace App\Models;

use App\Enums\OrganisationRole;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $organisation_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['organisation_id', 'name'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Organisation, $this>
     */
    public function organisation(): BelongsTo
    {
        return $this->belongsTo(Organisation::class);
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    /**
     * Projects the actor can see: every project in an organisation the user owns,
     * otherwise only projects holding at least one contract visible to the actor.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User|Party $actor): void
    {
        $query->where(function (Builder $query) use ($actor): void {
            if ($actor instanceof User) {
                $query->whereHas('organisation.users', fn (Builder $users) => $users
                    ->whereKey($actor->getKey())
                    ->where('organisation_user.role', OrganisationRole::Owner->value));
            }

            $query->orWhereHas('contracts', fn (Builder $contracts) => $contracts->visibleTo($actor));
        });
    }
}
