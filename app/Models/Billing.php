<?php

namespace App\Models;

use App\Domain\Billing\BillingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A progress billing — slide 6's central document.
 *
 * @property string $gross_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property string $retention_rate
 * @property string $retention_amount
 * @property string $deductions_amount
 * @property string $net_amount
 * @property BillingStatus $status
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property ?Carbon $submitted_at
 * @property ?Carbon $evaluated_at
 */
#[Fillable([
    'project_id', 'contract_id', 'billing_milestone_id', 'accomplishment_id',
    'number', 'status', 'period_start', 'period_end',
    'gross_amount', 'retention_rate', 'retention_amount', 'deductions_amount', 'net_amount',
    'submitted_at', 'submitted_by_user_id', 'evaluated_at',
    'returned_reason', 'remarks',
])]
class Billing extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BillingStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
            'gross_amount' => 'decimal:4',
            'retention_rate' => 'decimal:2',
            'retention_amount' => 'decimal:4',
            'deductions_amount' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'submitted_at' => 'datetime',
            'evaluated_at' => 'datetime',
        ];
    }

    /**
     * The statement of deductions — P5-05. Empty on every billing but the final
     * one, which is why the column defaults to zero rather than being derived.
     *
     * @return HasMany<FinalBillingDeduction, $this>
     */
    public function deductions(): HasMany
    {
        return $this->hasMany(FinalBillingDeduction::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return BelongsTo<BillingMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(BillingMilestone::class, 'billing_milestone_id');
    }

    /**
     * @return BelongsTo<Accomplishment, $this>
     */
    public function accomplishment(): BelongsTo
    {
        return $this->belongsTo(Accomplishment::class);
    }

    /**
     * One invoice per billing — the invoice screen offers only billings without
     * one, because a second invoice bills the client twice for one submission.
     *
     * @return HasOne<SalesInvoice, $this>
     */
    public function invoice(): HasOne
    {
        return $this->hasOne(SalesInvoice::class);
    }

    /**
     * @return HasMany<BillingLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BillingLine::class);
    }
}
