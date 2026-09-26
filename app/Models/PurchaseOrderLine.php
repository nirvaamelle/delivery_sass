<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One ordered line, carrying its own cost code.
 *
 * @property string $quantity
 * @property string $unit_price
 * @property string $line_total
 * @property string $quantity_received
 */
#[Fillable([
    'purchase_order_id', 'cost_code_id', 'description',
    'quantity', 'unit_price', 'line_total', 'unit', 'quantity_received',
])]
class PurchaseOrderLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'line_total' => 'decimal:4',
            'quantity_received' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
