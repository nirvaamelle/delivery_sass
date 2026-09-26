<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Material going back on the truck.
 *
 * @property string $quantity_returned
 * @property Carbon $returned_at
 */
#[Fillable(['inspection_id', 'vendor_id', 'number', 'quantity_returned', 'returned_at', 'remarks'])]
class ReturnToVendor extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_returned' => 'decimal:4',
            'returned_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Inspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
