<?php

namespace App\Models;

use App\Domain\Mobilization\MobilizationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Slide 3's step 5 — the document F2 says has no home.
 *
 * @property MobilizationStatus $status
 * @property Carbon $mobilized_on
 * @property ?Carbon $completed_at
 */
#[Fillable([
    'project_id', 'purchase_order_id', 'number', 'status',
    'mobilized_on', 'completed_at', 'completed_by_user_id', 'remarks',
])]
class Mobilization extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => MobilizationStatus::class,
            'mobilized_on' => 'date',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<MobilizationItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MobilizationItem::class);
    }
}
