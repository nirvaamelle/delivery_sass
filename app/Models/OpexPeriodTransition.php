<?php

namespace App\Models;

use App\Domain\Opex\OpexStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One move through the calendar, and who made it.
 *
 * @property OpexStage $from_stage
 * @property OpexStage $to_stage
 * @property Carbon $performed_at
 */
#[Fillable([
    'opex_period_id', 'from_stage', 'to_stage',
    'performed_at', 'performed_by_user_id', 'remarks',
])]
class OpexPeriodTransition extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_stage' => OpexStage::class,
            'to_stage' => OpexStage::class,
            'performed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<OpexPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(OpexPeriod::class, 'opex_period_id');
    }
}
