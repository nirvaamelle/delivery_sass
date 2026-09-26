<?php

namespace App\Models;

use App\Domain\Vendors\BondType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A surety, performance or warranty bond — F15.
 *
 * @property BondType $type
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property Carbon $effective_at
 * @property Carbon $expires_at
 */
#[Fillable(['vendor_id', 'type', 'amount', 'effective_at', 'expires_at', 'reference', 'issuer'])]
class VendorBond extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => BondType::class,
            'amount' => 'decimal:4',
            'effective_at' => 'date',
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
