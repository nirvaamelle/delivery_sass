<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Goods that failed in service — F11's missing suspension trigger.
 *
 * From P5-03 it also carries the certificate it is claimed under — F7's
 * "…and to any claim raised against it." The link is nullable, because goods
 * whose certificate nobody collected still fail, and that claim must stay
 * raisable or the suspension trigger is lost with the paperwork.
 *
 * @property Carbon $raised_at
 * @property ?Carbon $resolved_at
 * @property ?Carbon $failed_on
 */
#[Fillable([
    'vendor_id', 'purchase_order_id', 'warranty_id', 'number', 'description', 'failed_on',
    'raised_at', 'raised_by_user_id', 'resolved_at', 'resolved_by_user_id', 'resolution',
])]
class WarrantyClaim extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'raised_at' => 'datetime',
            'resolved_at' => 'datetime',
            'failed_on' => 'date',
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
     * @return BelongsTo<Warranty, $this>
     */
    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }
}
