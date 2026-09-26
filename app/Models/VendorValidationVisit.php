<?php

namespace App\Models;

use App\Domain\Vendors\ValidationVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A dated visit to a vendor's declared premises — F15.
 *
 * @property ValidationVerdict $verdict
 * @property Carbon $visited_at
 */
#[Fillable(['vendor_id', 'visited_at', 'verdict', 'findings', 'visited_by_user_id'])]
class VendorValidationVisit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visited_at' => 'date',
            'verdict' => ValidationVerdict::class,
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
