<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A recurring allowance paid every cutoff it is in force.
 *
 * Written only through AllowanceService. The amount is encrypted for the same
 * reason a rate is: an allowance beside a name is part of somebody's pay.
 *
 * @property string $amount Per cutoff.
 * @property bool $taxable
 * @property Carbon $effective_from
 * @property ?Carbon $effective_to
 */
#[Fillable(['employee_id', 'name', 'amount', 'taxable', 'effective_from', 'effective_to', 'created_by_user_id', 'ended_by_user_id'])]
class EmployeeAllowance extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'encrypted',
            'taxable' => 'boolean',
            'effective_from' => 'date',
            'effective_to' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
