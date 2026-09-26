<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One employee's pay for one cutoff.
 *
 * Every amount is a decimal string decrypted by its cast — ciphertext in the
 * column, per PLAN.md §3.
 *
 * @property string $basic_pay
 * @property string $overtime_pay
 * @property string $night_differential_pay
 * @property string $gross_pay
 * @property string $sss_contribution
 * @property string $philhealth_contribution
 * @property string $pagibig_contribution
 * @property string $withholding_tax
 * @property string $total_deductions
 * @property string $net_pay
 * @property int $days_paid
 * @property int $carried_days
 */
#[Fillable([
    'payroll_run_id', 'employee_id',
    'basic_pay', 'overtime_pay', 'night_differential_pay',
    'leave_pay', 'leave_days', 'taxable_allowances', 'non_taxable_allowances', 'gross_pay',
    'sss_contribution', 'philhealth_contribution', 'pagibig_contribution',
    'withholding_tax', 'total_deductions', 'net_pay',
    'days_paid', 'carried_days',
])]
class PayrollLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basic_pay' => 'encrypted',
            'overtime_pay' => 'encrypted',
            'night_differential_pay' => 'encrypted',
            // Benefits. Null on lines computed before they existed; read as zero.
            'leave_pay' => 'encrypted',
            'leave_days' => 'decimal:1',
            'taxable_allowances' => 'encrypted',
            'non_taxable_allowances' => 'encrypted',
            'gross_pay' => 'encrypted',
            'sss_contribution' => 'encrypted',
            'philhealth_contribution' => 'encrypted',
            'pagibig_contribution' => 'encrypted',
            'withholding_tax' => 'encrypted',
            'total_deductions' => 'encrypted',
            'net_pay' => 'encrypted',
            'days_paid' => 'integer',
            'carried_days' => 'integer',
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
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return HasMany<PayrollLineDay, $this>
     */
    public function days(): HasMany
    {
        return $this->hasMany(PayrollLineDay::class);
    }
}
