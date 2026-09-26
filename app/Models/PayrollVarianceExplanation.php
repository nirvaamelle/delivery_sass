<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Why one project's labour cost moved between cutoffs — F10.
 *
 * @property string $previous_amount
 * @property string $current_amount
 * @property string $variance
 * @property Carbon $explained_at
 */
#[Fillable([
    'payroll_run_id', 'project_id', 'previous_amount', 'current_amount',
    'variance', 'explanation', 'explained_at', 'explained_by_user_id',
])]
class PayrollVarianceExplanation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_amount' => 'decimal:4',
            'current_amount' => 'decimal:4',
            'variance' => 'decimal:4',
            'explained_at' => 'datetime',
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
