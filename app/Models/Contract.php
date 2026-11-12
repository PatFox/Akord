<?php

namespace App\Models;

use App\Enums\OrganisationRole;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $project_id
 * @property string $title
 * @property int $version
 * @property int|null $previous_version_id
 * @property Carbon|null $agreed_at
 * @property string|null $agreed_fingerprint
 * @property bool $require_human_signoff
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['project_id', 'title', 'version', 'previous_version_id', 'require_human_signoff'])]
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'agreed_at' => 'datetime',
            'require_human_signoff' => 'boolean',
        ];
    }

    /**
     * Agreed contracts are locked; only "start new version" may follow.
     *
     * @phpstan-impure reads mutable state (sign-off agrees the contract mid-transaction)
     */
    public function isAgreed(): bool
    {
        return $this->agreed_at !== null;
    }

    public function organisationId(): int
    {
        return $this->project->organisation_id;
    }

    /**
     * A phase of this contract; any other id is "not found" (nested binding check).
     */
    public function findPhase(int $phaseId): Phase
    {
        return Phase::query()->whereKey($phaseId)->where('contract_id', $this->getKey())->firstOrFail();
    }

    /**
     * A step of this contract, with its current revision; any other id is "not found".
     */
    public function findStep(int $stepId): Step
    {
        return Step::query()
            ->with('currentRevision')
            ->whereKey($stepId)
            ->whereRelation('phase', 'contract_id', $this->getKey())
            ->firstOrFail();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany<Party, $this, ContractParty>
     */
    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class)
            ->using(ContractParty::class)
            ->withPivot('role', 'is_required_approver')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Party, $this, ContractParty>
     */
    public function requiredApprovers(): BelongsToMany
    {
        return $this->parties()->wherePivot('is_required_approver', true);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'previous_version_id');
    }

    /**
     * @return HasOne<Contract, $this>
     */
    public function nextVersion(): HasOne
    {
        return $this->hasOne(Contract::class, 'previous_version_id');
    }

    /**
     * @return HasMany<Phase, $this>
     */
    public function phases(): HasMany
    {
        return $this->hasMany(Phase::class)->orderBy('position');
    }

    /**
     * @return HasManyThrough<Step, Phase, $this>
     */
    public function steps(): HasManyThrough
    {
        return $this->hasManyThrough(Step::class, Phase::class);
    }

    /**
     * @return HasMany<ContractSignoff, $this>
     */
    public function signoffs(): HasMany
    {
        return $this->hasMany(ContractSignoff::class);
    }

    /**
     * @return HasMany<Invitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /**
     * @return HasMany<ActivityEvent, $this>
     */
    public function activityEvents(): HasMany
    {
        return $this->hasMany(ActivityEvent::class);
    }

    /**
     * Contracts the actor can see. A user sees every contract in an organisation
     * they own, plus contracts attached to a party they belong to. An agent party
     * sees only contracts it is attached to.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User|Party $actor): void
    {
        if ($actor instanceof Party) {
            $query->whereHas('parties', fn (Builder $parties) => $parties->whereKey($actor->getKey()));

            return;
        }

        $query->where(fn (Builder $query) => $query
            ->whereHas('project.organisation.users', fn (Builder $users) => $users
                ->whereKey($actor->getKey())
                ->where('organisation_user.role', OrganisationRole::Owner->value))
            ->orWhereHas('parties.users', fn (Builder $users) => $users->whereKey($actor->getKey())));
    }
}
