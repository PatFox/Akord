<?php

namespace App\Models;

use App\Models\Concerns\HasRandomId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $checklist_stage_id
 * @property int $position
 * @property string $title
 * @property string|null $notes
 * @property string|null $step_id
 * @property Carbon|null $checked_at
 * @property string|null $checked_by_party_id
 * @property string|null $checked_by_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['checklist_stage_id', 'position', 'title', 'notes', 'step_id', 'checked_at', 'checked_by_party_id', 'checked_by_user_id'])]
class ChecklistItem extends Model
{
    use HasRandomId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    public function isChecked(): bool
    {
        return $this->checked_at !== null;
    }

    /**
     * @return BelongsTo<ChecklistStage, $this>
     */
    public function stage(): BelongsTo
    {
        return $this->belongsTo(ChecklistStage::class, 'checklist_stage_id');
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
    public function checkedByParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'checked_by_party_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function checkedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by_user_id');
    }
}
