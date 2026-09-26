<?php

namespace App\Models;

use Database\Factories\BudgetLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast below.
 *                          Declared explicitly because Larastan otherwise infers
 *                          `float` from the column type — which is precisely the
 *                          type PLAN.md §4 forbids money from ever becoming.
 */
#[Fillable(['budget_id', 'cost_code_id', 'amount'])]
class BudgetLine extends Model
{
    /** @use HasFactory<BudgetLineFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        // PLAN.md §4 — DECIMAL(18,4). The cast keeps the value a string all the
        // way through PHP so no float ever touches a money amount.
        return [
            'amount' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Budget, $this>
     */
    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
