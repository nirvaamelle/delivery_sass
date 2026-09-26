<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A claim for retention at the end of the defects liability period.
 *
 * Raised is not collected. The claim is the request the client pays against;
 * the ledger moves when the money lands.
 *
 * @property string $amount DECIMAL(18,4), surfaced as a string.
 * @property Carbon $claimed_on
 * @property ?Carbon $collected_on
 */
#[Fillable([
    'project_id', 'contract_id', 'number', 'amount',
    'claimed_on', 'claimed_by_user_id',
    'collected_on', 'collection_reference', 'collected_by_user_id',
    'retention_entry_id', 'remarks',
])]
class RetentionRelease extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'claimed_on' => 'date',
            'collected_on' => 'date',
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
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return BelongsTo<RetentionEntry, $this>
     */
    public function retentionEntry(): BelongsTo
    {
        return $this->belongsTo(RetentionEntry::class);
    }
}
