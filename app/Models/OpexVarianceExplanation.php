<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Why a cost code's actual missed its budget — slide 8's day 30.
 *
 * @property string $budget_amount
 * @property string $actual_amount
 * @property string $variance_amount
 * @property string $variance_percent
 * @property Carbon $explained_at
 */
#[Fillable([
    'opex_period_id', 'project_id', 'cost_code_id',
    'budget_amount', 'actual_amount', 'variance_amount', 'variance_percent',
    'explanation', 'explained_at', 'explained_by_user_id',
])]
class OpexVarianceExplanation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'budget_amount' => 'decimal:4',
            'actual_amount' => 'decimal:4',
            'variance_amount' => 'decimal:4',
            'variance_percent' => 'decimal:2',
            'explained_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OpexPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(OpexPeriod::class, 'opex_period_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
