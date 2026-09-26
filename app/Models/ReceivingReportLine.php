<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received line, carrying its own shortfall.
 *
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string $quantity_short
 */
#[Fillable([
    'receiving_report_id', 'purchase_order_line_id',
    'quantity_ordered', 'quantity_received', 'quantity_short', 'remarks',
])]
class ReceivingReportLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4',
            'quantity_received' => 'decimal:4',
            'quantity_short' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<ReceivingReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(ReceivingReport::class, 'receiving_report_id');
    }

    /**
     * @return BelongsTo<PurchaseOrderLine, $this>
     */
    public function purchaseOrderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }
}
