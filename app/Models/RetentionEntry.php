<?php

namespace App\Models;

use App\Domain\Billing\RetentionEntryType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One movement on the retention ledger.
 *
 * @property string $amount Signed: withheld positive, released negative.
 * @property RetentionEntryType $type
 * @property Carbon $entry_date
 */
#[Fillable([
    'project_id', 'contract_id', 'billing_id', 'type',
    'amount', 'entry_date', 'reference', 'recorded_by_user_id', 'remarks',
])]
class RetentionEntry extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => RetentionEntryType::class,
            'amount' => 'decimal:4',
            'entry_date' => 'date',
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
     * @return BelongsTo<Billing, $this>
     */
    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }
}
