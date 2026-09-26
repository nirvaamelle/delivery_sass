<?php

namespace App\Models;

use App\Domain\Hris\ContractStatus;
use App\Domain\Hris\PayBasis;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The contract slide 7 requires signed before the first shift.
 *
 * @property ContractStatus $status
 * @property PayBasis $pay_basis
 * @property string $rate Decrypted by the cast.
 * @property Carbon $effective_from
 * @property ?Carbon $effective_to
 * @property ?Carbon $signed_on
 * @property ?Carbon $signed_at
 */
#[Fillable([
    'employee_id', 'manpower_requisition_id', 'number', 'status',
    'effective_from', 'effective_to', 'pay_basis', 'rate', 'position',
    'signed_on', 'signed_at', 'signed_by', 'witnessed_by_user_id',
])]
class EmploymentContract extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'pay_basis' => PayBasis::class,
            'rate' => 'encrypted',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'signed_on' => 'date',
            'signed_at' => 'datetime',
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
     * @return BelongsTo<ManpowerRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(ManpowerRequisition::class, 'manpower_requisition_id');
    }

    /**
     * Does this contract cover a given day?
     *
     * Signed AND in term. The signature is necessary and it is not sufficient: a
     * contract signed in May for a June start does not authorise a May shift.
     */
    public function coversDay(Carbon $date): bool
    {
        if ($this->status !== ContractStatus::Signed) {
            return false;
        }

        if ($date->lt($this->effective_from)) {
            return false;
        }

        return $this->effective_to === null || ! $date->gt($this->effective_to);
    }
}
