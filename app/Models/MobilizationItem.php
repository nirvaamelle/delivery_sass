<?php

namespace App\Models;

use App\Domain\Mobilization\ChecklistItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the mobilization checklist.
 *
 * @property ChecklistItem $item
 * @property bool $required
 * @property ?Carbon $completed_at
 */
#[Fillable([
    'mobilization_id', 'item', 'required',
    'completed_at', 'completed_by_user_id', 'remarks',
])]
class MobilizationItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item' => ChecklistItem::class,
            'required' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Mobilization, $this>
     */
    public function mobilization(): BelongsTo
    {
        return $this->belongsTo(Mobilization::class);
    }
}
