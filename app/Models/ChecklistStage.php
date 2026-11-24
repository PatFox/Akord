<?php

namespace App\Models;

use App\Models\Concerns\HasRandomId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $checklist_id
 * @property string $title
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['checklist_id', 'title', 'position'])]
class ChecklistStage extends Model
{
    use HasRandomId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Checklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(Checklist::class);
    }

    /**
     * "N. Title", unless the title already starts with its own number ("1. Database", "1) Database"), as agents
     * often write them; then the title alone, so the number doesn't show twice.
     */
    public function heading(): string
    {
        return preg_match('/^'.$this->position.'[.):](?!\d)/', $this->title) === 1 ? $this->title : $this->position.'. '.$this->title;
    }

    /**
     * @return HasMany<ChecklistItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ChecklistItem::class)->orderBy('position');
    }
}
