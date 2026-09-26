<?php

namespace App\Models;

use App\Domain\Procurement\InspectionVerdict;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Whether what arrived is acceptable.
 *
 * @property InspectionVerdict $verdict
 * @property Carbon $inspected_at
 */
#[Fillable(['receiving_report_id', 'number', 'verdict', 'inspected_at', 'inspected_by_user_id', 'remarks'])]
class Inspection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verdict' => InspectionVerdict::class,
            'inspected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ReceivingReport, $this>
     */
    public function receivingReport(): BelongsTo
    {
        return $this->belongsTo(ReceivingReport::class);
    }

    /**
     * @return HasMany<InspectionLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InspectionLine::class);
    }

    /**
     * @return HasOne<ReturnToVendor, $this>
     */
    public function returnToVendor(): HasOne
    {
        return $this->hasOne(ReturnToVendor::class);
    }
}
