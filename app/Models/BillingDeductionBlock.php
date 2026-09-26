<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A deducted line, blocked from the next submission until it is re-measured.
 *
 * Lives at project level rather than on the billing it came from, because the
 * billing it came from is finished and the submission it has to stop has not
 * been written yet.
 *
 * @property Carbon $blocked_at
 * @property ?Carbon $cleared_at
 */
#[Fillable([
    'project_id', 'line_key', 'billing_id', 'reason',
    'blocked_at', 'cleared_at', 'cleared_by_user_id', 'clearance_note',
])]
class BillingDeductionBlock extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'blocked_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Billing, $this>
     */
    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }
}
