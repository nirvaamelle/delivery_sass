<?php

namespace App\Models;

use App\Domain\Closeout\CloseOutEvidence;
use App\Domain\Closeout\CloseOutPanel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the close-out checklist.
 *
 * No status column: `cleared_at` is the clearance and the name beside it is
 * what the report is made of.
 *
 * @property CloseOutPanel $panel
 * @property CloseOutEvidence $evidence
 * @property ?Carbon $cleared_at
 */
#[Fillable([
    'close_out_checklist_id', 'sequence', 'panel', 'document_key', 'label', 'evidence',
    'cleared_at', 'cleared_by_user_id', 'note',
])]
class CloseOutChecklistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'panel' => CloseOutPanel::class,
            'evidence' => CloseOutEvidence::class,
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CloseOutChecklist, $this>
     */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(CloseOutChecklist::class, 'close_out_checklist_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }
}
