<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * PO ↔ receiving report ↔ invoice, agreed.
 *
 * The row is written only when the three agree, so it is evidence rather than a
 * workspace: nothing here is edited afterwards, and a disagreement is an
 * exception at the point of matching, not a document with a `failed` flag.
 *
 * @property string $invoice_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property string $ordered_amount
 * @property string $accepted_amount
 * @property bool $matched
 * @property Carbon $matched_at
 */
#[Fillable([
    'purchase_order_id', 'receiving_report_id', 'inspection_id',
    'number', 'invoice_reference', 'invoice_amount', 'ordered_amount',
    'accepted_amount', 'matched', 'matched_at', 'matched_by_user_id',
])]
class ThreeWayMatch extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_amount' => 'decimal:4',
            'ordered_amount' => 'decimal:4',
            'accepted_amount' => 'decimal:4',
            'matched' => 'boolean',
            'matched_at' => 'datetime',
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
     * @return BelongsTo<ReceivingReport, $this>
     */
    public function receivingReport(): BelongsTo
    {
        return $this->belongsTo(ReceivingReport::class);
    }

    /**
     * @return BelongsTo<Inspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /**
     * @return HasOne<ApVoucher, $this>
     */
    public function voucher(): HasOne
    {
        return $this->hasOne(ApVoucher::class);
    }
}
