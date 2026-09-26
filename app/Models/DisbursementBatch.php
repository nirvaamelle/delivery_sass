<?php

namespace App\Models;

use App\Domain\Hris\DisbursementMethod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One register's worth of payments, by one method.
 *
 * @property DisbursementMethod $method
 * @property string $total_amount
 * @property Carbon $prepared_at
 * @property ?Carbon $transmitted_at
 */
#[Fillable([
    'payroll_run_id', 'number', 'method', 'total_amount', 'file_path',
    'prepared_at', 'prepared_by_user_id',
    'transmitted_at', 'bank_reference', 'transmitted_by_user_id',
])]
class DisbursementBatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'method' => DisbursementMethod::class,
            'total_amount' => 'decimal:4',
            'prepared_at' => 'datetime',
            'transmitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return HasMany<DisbursementItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DisbursementItem::class);
    }
}
