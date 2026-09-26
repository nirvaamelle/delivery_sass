<?php

namespace App\Models;

use App\Domain\Contracts\ContractStatus;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $contract_sum DECIMAL(18,4), surfaced as a string by the cast.
 * @property string $retention_rate DECIMAL(9,6), likewise — a rate is not money,
 *                                  but it is no more allowed to become a float.
 * @property ContractStatus $status
 * @property string $number
 * @property ?Carbon $signed_at
 * @property ?Carbon $completed_on When the works were accepted; the defects
 *                                 liability period runs from here.
 */
#[Fillable([
    'project_id',
    'number',
    'status',
    'noa_date',
    'ntp_date',
    'contract_sum',
    'retention_rate',
    'defects_liability_days',
    'signed_at',
    'completed_on',
])]
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'noa_date' => 'date',
            'ntp_date' => 'date',
            'contract_sum' => 'decimal:4',
            'retention_rate' => 'decimal:6',
            'defects_liability_days' => 'integer',
            'signed_at' => 'datetime',
            'completed_on' => 'date',
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
     * Built when the contract is signed — one schedule per contract.
     *
     * @return HasOne<BillingSchedule, $this>
     */
    public function schedule(): HasOne
    {
        return $this->hasOne(BillingSchedule::class);
    }

    /**
     * The milestones the project bills against, reached through the schedule so
     * the contract screen can list them directly.
     *
     * @return HasManyThrough<BillingMilestone, BillingSchedule, $this>
     */
    public function milestones(): HasManyThrough
    {
        return $this->hasManyThrough(BillingMilestone::class, BillingSchedule::class);
    }
}
