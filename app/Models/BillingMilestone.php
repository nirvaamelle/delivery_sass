<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of slide 6's five milestones, with its own trigger and its own documents.
 *
 * @property string $percentage
 * @property ?string $accomplishment_threshold
 * @property int $sequence
 */
#[Fillable([
    'billing_schedule_id', 'code', 'name', 'percentage',
    'trigger', 'accomplishment_threshold', 'sequence',
])]
class BillingMilestone extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'accomplishment_threshold' => 'decimal:2',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<BillingSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(BillingSchedule::class, 'billing_schedule_id');
    }

    /**
     * @return HasMany<MilestoneDocumentRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(MilestoneDocumentRequirement::class);
    }

    /**
     * @return HasMany<MilestoneDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(MilestoneDocument::class);
    }

    /**
     * What has been billed against this milestone. The submission screen offers
     * only milestones with nothing billed yet.
     *
     * @return HasMany<Billing, $this>
     */
    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class);
    }
}
