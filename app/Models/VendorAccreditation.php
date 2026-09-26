<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One accreditation period. Renewals append rather than overwrite, so
 * "was this vendor accredited when that PO was raised" stays answerable.
 *
 * @property Carbon $accredited_at
 * @property Carbon $expires_at
 */
#[Fillable(['vendor_id', 'accredited_at', 'expires_at', 'certificate_reference', 'accredited_by_user_id'])]
class VendorAccreditation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accredited_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }
}
