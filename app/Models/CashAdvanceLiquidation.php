<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accounting of advanced money: an expense, or cash handed back.
 *
 * @property string $amount
 * @property Carbon $liquidated_on
 */
#[Fillable([
    'cash_advance_id', 'expense_id', 'cash_return_reference',
    'amount', 'liquidated_on', 'recorded_by_user_id',
])]
class CashAdvanceLiquidation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'liquidated_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<CashAdvance, $this>
     */
    public function advance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class, 'cash_advance_id');
    }

    /**
     * @return BelongsTo<Expense, $this>
     */
    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }
}
