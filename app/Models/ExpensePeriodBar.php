<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A receipt barred from a period after it was returned to the site.
 *
 * @property int $period_year
 * @property int $period_month
 * @property Carbon $barred_at
 * @property ?Carbon $cleared_at
 */
#[Fillable([
    'project_id', 'receipt_reference', 'period_year', 'period_month',
    'expense_id', 'reason', 'barred_at', 'barred_by_user_id',
    'cleared_at', 'cleared_by_user_id', 'clearance_note',
])]
class ExpensePeriodBar extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'barred_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
