<?php

namespace App\Models;

use App\Domain\Opex\OpexStage;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month of slide 8's calendar, and where it has got to.
 *
 * @property OpexStage $stage
 * @property int $period_year
 * @property int $period_month
 * @property Carbon $opened_at
 * @property ?Carbon $cutoff_at
 * @property ?Carbon $closed_at
 */
#[Fillable([
    'organization_id', 'period_year', 'period_month', 'stage',
    'opened_at', 'cutoff_at', 'closed_at',
])]
class OpexPeriod extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stage' => OpexStage::class,
            'period_year' => 'integer',
            'period_month' => 'integer',
            'opened_at' => 'datetime',
            'cutoff_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<OpexPeriodTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(OpexPeriodTransition::class);
    }
}
