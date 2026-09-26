<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's payment out of a batch.
 *
 * @property string $amount Decrypted by the cast.
 * @property ?Carbon $released_at
 * @property ?Carbon $acknowledged_on
 */
#[Fillable([
    'disbursement_batch_id', 'payroll_line_id', 'employee_id', 'amount',
    'released_at', 'acknowledged_on', 'acknowledged_by', 'witnessed_by_user_id',
])]
class DisbursementItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'encrypted',
            'released_at' => 'datetime',
            'acknowledged_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<DisbursementBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(DisbursementBatch::class, 'disbursement_batch_id');
    }

    /**
     * @return BelongsTo<PayrollLine, $this>
     */
    public function line(): BelongsTo
    {
        return $this->belongsTo(PayrollLine::class, 'payroll_line_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
