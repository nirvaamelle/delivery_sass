<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One billable item, carrying the key that identifies it across submissions.
 *
 * @property string $amount
 * @property bool $deducted
 */
#[Fillable([
    'billing_id', 'line_key', 'description', 'amount',
    'cost_code_id', 'deducted', 'deduction_reason',
])]
class BillingLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'deducted' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Billing, $this>
     */
    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
