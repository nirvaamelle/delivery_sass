<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A rendered payslip, on the private disk.
 *
 * @property Carbon $rendered_at
 */
#[Fillable(['payroll_line_id', 'employee_id', 'file_path', 'rendered_at'])]
class Payslip extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rendered_at' => 'datetime'];
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
