<?php

namespace App\Models;

use App\Models\Concerns\HasRandomId;
use Database\Factories\StepRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only snapshot of a step's title and description. A revision can propose archiving the step (D16): its
 * text is the previous revision's, and the step leaves the contract once every required party approves it.
 *
 * @property string $id
 * @property string $step_id
 * @property int $number
 * @property string $title
 * @property string $description_md
 * @property bool $proposes_archive
 * @property string $content_hash
 * @property string $author_party_id
 * @property string|null $author_user_id
 * @property string|null $change_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['step_id', 'number', 'title', 'description_md', 'proposes_archive', 'author_party_id', 'author_user_id', 'change_note'])]
class StepRevision extends Model
{
    /** @use HasFactory<StepRevisionFactory> */
    use HasFactory, HasRandomId;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'proposes_archive' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (StepRevision $revision): void {
            $revision->content_hash = static::contentHash($revision->title, $revision->description_md, $revision->proposes_archive);
        });

        static::updating(fn () => throw new LogicException('Step revisions are append-only.'));
        static::deleting(fn () => throw new LogicException('Step revisions are append-only.'));
    }

    /**
     * Hash of the title and description that ignores spacing-only differences (D11):
     * trim, normalise line endings, and collapse every whitespace run to one space.
     * Proposing to archive the step is a change of its own (D16): approvals of the text do not approve it, and
     * approvals of the proposal do not count for the text. Hashes of other revisions are unchanged, since stored
     * sign-off fingerprints are built from them.
     */
    public static function contentHash(string $title, string $descriptionMd, bool $proposesArchive = false): string
    {
        $normalise = fn (string $text): string => (string) preg_replace('/\s+/u', ' ', trim(str_replace("\r\n", "\n", $text)));
        $content = [$normalise($title), $normalise($descriptionMd)];

        return hash('sha256', (string) json_encode($proposesArchive ? [...$content, 'archive'] : $content));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'proposes_archive' => 'boolean',
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
