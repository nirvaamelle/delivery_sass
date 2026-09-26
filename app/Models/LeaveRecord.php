<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A day, or half a day, of paid leave.
 *
 * Written only through LeaveService. `payroll_line_id` is set when a payroll run
 * pays it, and a paid record cannot be cancelled — the same one-payment rule a
 * DTR day has.
 *
 * @property Carbon $leave_date
 * @property string $days
 * @property ?Carbon $cancelled_at
 */
#[Fillable([
    'employee_id', 'leave_type', 'leave_date', 'days', 'reason',
    'recorded_by_user_id', 'payroll_line_id', 'cancelled_at', 'cancelled_by_user_id', 'cancellation_reason',
])]
class LeaveRecord extends Model
{
    public const SERVICE_INCENTIVE = 'service_incentive';

    protected function casts(): array
    {
        return [
            'leave_date' => 'date',
            'days' => 'decimal:1',
            'cancelled_at' => 'datetime',
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
     * @return BelongsTo<PayrollLine, $this>
     */
    public function payrollLine(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class);
    }
}
