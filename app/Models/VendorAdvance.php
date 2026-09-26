<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money released against an order before anything arrives — F8.
 *
 * It is deliberately not a voucher. A voucher answers "these goods arrived and
 * this invoice is right"; an advance answers "work cannot start until the
 * supplier is funded". Modelling the second as the first would require inventing
 * a delivery, and the invented delivery is what a three-way match exists to
 * catch.
 *
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property string $offset_amount
 * @property Carbon $released_at
 */
#[Fillable([
    'purchase_order_id', 'vendor_id', 'number', 'amount',
    'offset_amount', 'offset_ap_voucher_id', 'purpose',
    'released_at', 'released_by_user_id',
])]
class VendorAdvance extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'offset_amount' => 'decimal:4',
            'released_at' => 'datetime',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<ApVoucher, $this>
     */
    public function offsetVoucher(): BelongsTo
    {
        return $this->belongsTo(ApVoucher::class, 'offset_ap_voucher_id');
    }
}
