<?php

namespace App\Models;

use App\Domain\Closeout\FinalDeductionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the final billing's statement of deductions.
 *
 * @property FinalDeductionType $type
 * @property string $amount DECIMAL(18,4), surfaced as a string.
 * @property Carbon $applied_at
 */
#[Fillable([
    'billing_id', 'type', 'back_charge_id', 'description', 'amount',
    'applied_at', 'applied_by_user_id',
])]
class FinalBillingDeduction extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinalDeductionType::class,
            'amount' => 'decimal:4',
            'applied_at' => 'datetime',
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
     * @return BelongsTo<BackCharge, $this>
     */
    public function backCharge(): BelongsTo
    {
        return $this->belongsTo(BackCharge::class);
    }
}
