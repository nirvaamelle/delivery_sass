<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * F17's cross-chain write: an unliquidated advance, become a payroll deduction.
 *
 * @property string $amount
 * @property int $period_year
 * @property int $period_month
 * @property Carbon $raised_at
 * @property ?Carbon $applied_at
 */
#[Fillable([
    'employee_id', 'cash_advance_id', 'reason', 'amount',
    'period_year', 'period_month', 'raised_at',
    'applied_at', 'payroll_line_id',
])]
class PayrollDeduction extends Model
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
            'raised_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<CashAdvance, $this>
     */
    public function cashAdvance(): BelongsTo
    {
        return $this->belongsTo(CashAdvance::class);
    }

    /**
     * @return BelongsTo<PayrollLine, $this>
     */
    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }
}
