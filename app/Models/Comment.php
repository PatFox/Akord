<?php

namespace App\Models;

use App\Enums\CommentKind;
use Database\Factories\CommentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $step_id
 * @property int $step_revision_id
 * @property int|null $parent_id
 * @property int $author_party_id
 * @property int|null $author_user_id
 * @property CommentKind $kind
 * @property string $body_md
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['step_id', 'step_revision_id', 'parent_id', 'author_party_id', 'author_user_id', 'kind', 'body_md', 'resolved_at'])]
class Comment extends Model
{
    /** @use HasFactory<CommentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => CommentKind::class,
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Step, $this>
     */
    public function step(): BelongsTo
    {
        return $this->belongsTo(Step::class);
    }

    /**
     * The revision this comment discusses.
     *
     * @return BelongsTo<StepRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(StepRevision::class, 'step_revision_id');
    }

    /**
     * @return BelongsTo<Comment, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Comment::class, 'parent_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function replies(): HasMany
    {
        return $this->hasMany(Comment::class, 'parent_id');
    }

    /**
     * @return BelongsTo<Party, $this>
     */
    public function authorParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'author_party_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function authorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
