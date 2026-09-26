<?php

namespace App\Models;

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Procurement\SoleSourceReason;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The written explanation for skipping a canvass, and its escalated approval.
 *
 * `tier` is guarded because it is the whole control. If it could be edited down
 * after the fact, "approved one level up" would describe a document that was
 * approved at whatever level was convenient — and the record would still read
 * as though the escalation had happened.
 *
 * @property SoleSourceReason $reason
 * @property string $amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property int $tier
 */
#[Fillable(['rfq_id', 'vendor_id', 'number', 'reason', 'narrative', 'amount', 'tier'])]
class SoleSourceJustification extends Model
{
    private const SERVICE_ONLY = ['rfq_id', 'vendor_id', 'number', 'amount', 'tier'];

    private static bool $withinService = false;

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
        static::updating(function (SoleSourceJustification $justification): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($justification->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'Justification %s: "%s" is part of the sole-source control and cannot be changed by a direct update.',
                        $justification->number ?? '(new)',
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
            'reason' => SoleSourceReason::class,
            'amount' => 'decimal:4',
            'tier' => 'integer',
        ];
    }

    /**
     * Has the escalated tier signed in full?
     *
     * Every step, not the first. A tier with two approvers that acts on one
     * signature is a tier with one approver, and the escalation would be
     * cosmetic.
     */
    public function isApproved(): bool
    {
        return $this->approvals()->exists()
            && ! $this->approvals()->where('decision', ApprovalDecision::Pending)->exists()
            && ! $this->approvals()->where('decision', ApprovalDecision::Returned)->exists();
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
