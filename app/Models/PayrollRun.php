<?php

namespace App\Models;

use App\Domain\Hris\PayrollRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One cutoff's payroll for one organization.
 *
 * @property PayrollRunStatus $status
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property ?Carbon $queued_at
 * @property ?Carbon $computed_at
 * @property ?Carbon $approved_at
 * @property ?Carbon $released_at
 * @property ?Carbon $posted_at When labour cost reached the ledger.
 * @property string $gross_total
 * @property string $net_total
 * @property ?array<int, array{employee_number: string, reason: string}> $exceptions
 */
#[Fillable([
    'organization_id', 'number', 'status', 'period_start', 'period_end',
    'opened_by_user_id', 'queued_at', 'computed_at', 'computed_by_user_id',
    'approved_at', 'approved_by_user_id', 'released_at', 'posted_at',
    'gross_total', 'net_total', 'exceptions',
])]
class PayrollRun extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PayrollRunStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'queued_at' => 'datetime',
            'computed_at' => 'datetime',
            'approved_at' => 'datetime',
            'released_at' => 'datetime',
            'posted_at' => 'datetime',
            'gross_total' => 'decimal:4',
            'net_total' => 'decimal:4',
            'exceptions' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * The disbursement batch prepared from this register, if one has been.
     *
     * One batch per run: the screen offers "prepare payment" only while there is
     * none, because a second batch pays the same register twice.
     *
     * @return HasOne<DisbursementBatch, $this>
     */
    public function disbursementBatch(): HasOne
    {
        return $this->hasOne(DisbursementBatch::class);
    }
}
