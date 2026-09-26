<?php

namespace App\Models;

use App\Domain\Procurement\RfqStatus;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A request for quotation.
 *
 * Four columns on this record are the ones PLAN.md §5's controls are measured
 * against, so none of them may be changed by an ordinary update. Found by
 * security review of P1-03: the service comment claimed sole source "is
 * declared when the RFQ is opened", which was an invariant asserted in prose
 * and enforced nowhere. `$rfq->update(['sole_source' => true])` on a draft
 * dodged the three-quote minimum completely, and the resulting document looked
 * as though the rule had never applied to it.
 *
 * Guarded at the model rather than by leaving the fields out of `$fillable`,
 * because omitting them makes the write silently do nothing — the caller
 * believes it succeeded. A refusal that is not visible is barely a refusal.
 *
 * @property RfqStatus $status
 * @property Carbon $quotation_deadline
 * @property ?Carbon $issued_at
 */
#[Fillable([
    'purchase_requisition_id', 'number', 'status',
    'quotation_deadline', 'issued_at', 'sole_source', 'cancellation_reason',
])]
class Rfq extends Model
{
    /**
     * Columns that only RfqService may change after creation.
     */
    private const SERVICE_ONLY = [
        'purchase_requisition_id',
        'number',
        'status',
        'issued_at',
        'sole_source',
    ];

    /**
     * True only inside RfqService::mutate().
     */
    private static bool $withinService = false;

    /**
     * Run a write that is allowed to touch the guarded columns.
     *
     * Deliberately narrow and deliberately awkward to reach: the point is that
     * the state machine has exactly one door, and code that wants to change an
     * RFQ's standing has to go through the service to find it.
     */
    public static function mutate(Closure $callback): mixed
    {
        self::$withinService = true;

        try {
            return $callback();
        } finally {
            self::$withinService = false;
        }
    }

    protected static function booted(): void
    {
        static::updating(function (Rfq $rfq): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($rfq->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'RFQ %s: "%s" is part of the procurement controls and cannot be changed by a direct update. Use RfqService.',
                        $rfq->number ?? '(new)',
                        $column,
                    ));
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RfqStatus::class,
            'quotation_deadline' => 'date',
            'issued_at' => 'datetime',
            'sole_source' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PurchaseRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    /**
     * @return HasMany<RfqRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(RfqRecipient::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * @return HasOne<BidTabulation, $this>
     */
    public function tabulation(): HasOne
    {
        return $this->hasOne(BidTabulation::class);
    }

    /**
     * The written justification a sole source needs before it can be awarded.
     *
     * @return HasOne<SoleSourceJustification, $this>
     */
    public function justification(): HasOne
    {
        return $this->hasOne(SoleSourceJustification::class);
    }
}
