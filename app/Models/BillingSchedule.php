<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The contract's milestone plan — slide 6.
 */
#[Fillable(['contract_id', 'contract_type'])]
class BillingSchedule extends Model
{
    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return HasMany<BillingMilestone, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(BillingMilestone::class);
    }
}
