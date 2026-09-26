<?php

namespace App\Models;

use App\Domain\Closeout\PunchlistResponsibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One defect, and the name of whoever made it good.
 *
 * There is no status column. Slide 9: "close-out is a checklist with named
 * clearers per line, not a status flag." `cleared_at` is the truth; `isOpen()`
 * reads it rather than a second column that could disagree with it.
 *
 * @property int $item_no
 * @property PunchlistResponsibility $responsibility
 * @property Carbon $raised_on
 * @property ?Carbon $due_on
 * @property ?Carbon $cleared_at
 */
#[Fillable([
    'punchlist_id', 'item_no', 'description', 'location',
    'responsibility', 'subcontract_id', 'raised_on', 'due_on',
    'cleared_at', 'cleared_by_user_id', 'clearance_note',
])]
class PunchlistItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_no' => 'integer',
            'responsibility' => PunchlistResponsibility::class,
            'raised_on' => 'date',
            'due_on' => 'date',
            'cleared_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->cleared_at === null;
    }

    /**
     * @return BelongsTo<Punchlist, $this>
     */
    public function punchlist(): BelongsTo
    {
        return $this->belongsTo(Punchlist::class);
    }

    /**
     * @return BelongsTo<Subcontract, $this>
     */
    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function clearedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cleared_by_user_id');
    }
}
