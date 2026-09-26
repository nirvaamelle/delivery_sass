<?php

namespace App\Models;

use App\Domain\Opex\CashAdvanceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Money advanced to somebody on site — slide 8's day-26 subject.
 *
 * @property CashAdvanceStatus $status
 * @property string $amount
 * @property Carbon $released_on
 * @property ?Carbon $charged_to_payroll_on
 * @property ?string $charged_amount
 */
#[Fillable([
    'employee_id', 'project_id', 'number', 'status', 'amount',
    'released_on', 'purpose', 'released_by_user_id',
    'charged_to_payroll_on', 'charged_amount',
])]
class CashAdvance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CashAdvanceStatus::class,
            'amount' => 'decimal:4',
            'released_on' => 'date',
            'charged_to_payroll_on' => 'date',
            'charged_amount' => 'decimal:4',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<CashAdvanceLiquidation, $this>
     */
    public function liquidations(): HasMany
    {
        return $this->hasMany(CashAdvanceLiquidation::class);
    }
}
