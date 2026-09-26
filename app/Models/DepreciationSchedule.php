<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One month's depreciation, computed once when the schedule is built.
 *
 * Computed rather than derived on read for the same reason the receiving report
 * snapshots its ordered quantity: the answer must not change when somebody edits
 * the machine's useful life two years in.
 *
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property int $period_year
 * @property int $period_month
 * @property ?Carbon $posted_at
 */
#[Fillable([
    'equipment_id', 'period_year', 'period_month', 'amount',
    'posted_at', 'project_cost_ledger_entry_id',
])]
class DepreciationSchedule extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
