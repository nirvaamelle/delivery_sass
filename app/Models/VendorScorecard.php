<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One supplier, one purchase order, four dimensions — F12.
 *
 * Every score is derived from the chain when the card is written, never typed
 * in. A scorecard a buyer can fill in is a record of who likes which supplier,
 * and the suspension rules that read it would then be enforcing an opinion.
 *
 * @property string $price_score 0.00–100.00, surfaced as a string by the cast.
 * @property string $delivery_score
 * @property string $quality_score
 * @property string $documents_score
 * @property string $overall_score
 * @property string $rejection_rate
 * @property int $days_late
 * @property int $period_year
 * @property int $period_quarter
 * @property Carbon $rated_at
 */
#[Fillable([
    'vendor_id', 'purchase_order_id', 'subcontract_id', 'period_year', 'period_quarter',
    'price_score', 'delivery_score', 'quality_score', 'documents_score',
    'overall_score', 'days_late', 'rejection_rate', 'rated_at', 'rated_by_user_id',
])]
class VendorScorecard extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_score' => 'decimal:2',
            'delivery_score' => 'decimal:2',
            'quality_score' => 'decimal:2',
            'documents_score' => 'decimal:2',
            'overall_score' => 'decimal:2',
            'rejection_rate' => 'decimal:2',
            'days_late' => 'integer',
            'period_year' => 'integer',
            'period_quarter' => 'integer',
            'rated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * Exactly one of this and `purchaseOrder()` is set — P5-09's constraint.
     *
     * @return BelongsTo<Subcontract, $this>
     */
    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class);
    }
}
