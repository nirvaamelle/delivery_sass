<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What the project made, as certified at close-out.
 *
 * @property string $revenue DECIMAL(18,4), surfaced as a string.
 * @property string $total_cost
 * @property string $gross_profit
 * @property string $margin_percent
 * @property string $contract_sum
 * @property string $revenue_remaining
 * @property string $committed_cost
 * @property string $forecast_final_cost
 * @property string $forecast_gross_profit
 * @property Carbon $filed_at
 */
#[Fillable([
    'project_id', 'contract_id', 'number',
    'revenue', 'material_cost', 'subcontract_cost', 'labor_cost', 'overhead_cost',
    'total_cost', 'gross_profit', 'margin_percent',
    'contract_sum', 'revenue_remaining', 'committed_cost',
    'forecast_final_cost', 'forecast_gross_profit',
    'filed_at', 'filed_by_user_id', 'remarks',
])]
class FinalAccount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revenue' => 'decimal:4',
            'material_cost' => 'decimal:4',
            'subcontract_cost' => 'decimal:4',
            'labor_cost' => 'decimal:4',
            'overhead_cost' => 'decimal:4',
            'total_cost' => 'decimal:4',
            'gross_profit' => 'decimal:4',
            'margin_percent' => 'decimal:2',
            'contract_sum' => 'decimal:4',
            'revenue_remaining' => 'decimal:4',
            'committed_cost' => 'decimal:4',
            'forecast_final_cost' => 'decimal:4',
            'forecast_gross_profit' => 'decimal:4',
            'filed_at' => 'datetime',
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
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function filedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filed_by_user_id');
    }
}
