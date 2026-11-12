<?php

namespace App\Models;

use Database\Factories\StepFactory;
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
 * @property int $phase_id
 * @property int $position
 * @property int|null $current_revision_id
 * @property Carbon|null $archived_at
 * @property int|null $origin_step_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['phase_id', 'position', 'current_revision_id', 'archived_at', 'origin_step_id'])]
class Step extends Model
{
    /** @use HasFactory<StepFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @return BelongsTo<Phase, $this>
     */
    public function phase(): BelongsTo
    {
        return $this->belongsTo(Phase::class);
    }

    /**
     * @return HasMany<StepRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(StepRevision::class)->orderBy('number');
    }

    /**
     * @return BelongsTo<StepRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(StepRevision::class, 'current_revision_id');
    }

    /**
     * The same step in the first contract version it appeared in.
     *
     * @return BelongsTo<Step, $this>
     */
    public function originStep(): BelongsTo
    {
        return $this->belongsTo(Step::class, 'origin_step_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class);
    }
}
