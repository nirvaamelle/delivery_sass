<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Material leaving the warehouse for the works.
 *
 * @property string $quantity
 * @property Carbon $issued_at
 */
#[Fillable([
    'stock_card_id', 'cost_code_id', 'number', 'quantity',
    'issued_to', 'purpose', 'issued_at', 'issued_by_user_id',
    'posted_at', 'project_cost_ledger_entry_id',
])]
class MaterialIssuance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'issued_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockCard, $this>
     */
    public function stockCard(): BelongsTo
    {
        return $this->belongsTo(StockCard::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
