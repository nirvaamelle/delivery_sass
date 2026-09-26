<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One document actually on file against a milestone.
 *
 * @property Carbon $submitted_at
 */
#[Fillable([
    'billing_milestone_id', 'document_key', 'reference',
    'submitted_at', 'submitted_by_user_id', 'remarks',
])]
class MilestoneDocument extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['submitted_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<BillingMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(BillingMilestone::class, 'billing_milestone_id');
    }
}
