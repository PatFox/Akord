<?php

namespace App\Models;

use Database\Factories\StepRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only snapshot of a step's title and description.
 *
 * @property int $id
 * @property int $step_id
 * @property int $number
 * @property string $title
 * @property string $description_md
 * @property string $content_hash
 * @property int $author_party_id
 * @property int|null $author_user_id
 * @property string|null $change_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['step_id', 'number', 'title', 'description_md', 'author_party_id', 'author_user_id', 'change_note'])]
class StepRevision extends Model
{
    /** @use HasFactory<StepRevisionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (StepRevision $revision): void {
            $revision->content_hash = static::contentHash($revision->title, $revision->description_md);
        });

        static::updating(fn () => throw new LogicException('Step revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Step revisions are append-only.'));
    }

    /**
     * Hash of the title and description that ignores spacing-only differences (D11):
     * trim, normalise line endings, and collapse every whitespace run to one space.
     */
    public static function contentHash(string $title, string $descriptionMd): string
    {
        $normalise = fn (string $text): string => (string) preg_replace('/\s+/u', ' ', trim(str_replace("\r\n", "\n", $text)));

        return hash('sha256', (string) json_encode([$normalise($title), $normalise($descriptionMd)]));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
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
