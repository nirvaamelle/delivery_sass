<?php

namespace App\Models;

use App\Domain\Procurement\PurchaseOrderStatus;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The award. Everything downstream — delivery, receipt, three-way match,
 * payment — hangs off this document.
 *
 * The guarded columns are the ones that would let the chain be rewritten from
 * the middle: the two predecessors that satisfy PLAN.md §5's gate, the vendor
 * that was awarded, the amount committed, and the status the receiving and
 * payment gates test against.
 *
 * @property string $total_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property PurchaseOrderStatus $status
 * @property ?Carbon $delivery_date The promised date the scorecard measures lateness against.
 * @property ?Carbon $countersigned_at
 */
#[Fillable([
    'purchase_requisition_id', 'bid_tabulation_id', 'vendor_id', 'project_id',
    'number', 'status', 'total_amount', 'sole_source',
    'delivery_date', 'delivery_address', 'terms',
    'submitted_at', 'countersigned_at', 'countersigned_by',
])]
class PurchaseOrder extends Model
{
    private const SERVICE_ONLY = [
        'purchase_requisition_id', 'bid_tabulation_id', 'vendor_id', 'project_id',
        'number', 'status', 'total_amount', 'sole_source',
    ];

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
        static::updating(function (PurchaseOrder $order): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($order->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'Purchase order %s: "%s" is part of the award record and cannot be changed by a direct update. Use PurchaseOrderService.',
                        $order->number ?? '(new)',
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
            'status' => PurchaseOrderStatus::class,
            'total_amount' => 'decimal:4',
            'sole_source' => 'boolean',
            'delivery_date' => 'date',
            'submitted_at' => 'datetime',
            'countersigned_at' => 'datetime',
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
     * @return BelongsTo<BidTabulation, $this>
     */
    public function tabulation(): BelongsTo
    {
        return $this->belongsTo(BidTabulation::class, 'bid_tabulation_id');
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<PurchaseOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /**
     * @return HasMany<ReceivingReport, $this>
     */
    public function receivingReports(): HasMany
    {
        return $this->hasMany(ReceivingReport::class);
    }

    /**
     * @return HasMany<VendorAdvance, $this>
     */
    public function advances(): HasMany
    {
        return $this->hasMany(VendorAdvance::class);
    }

    /**
     * @return HasMany<ThreeWayMatch, $this>
     */
    public function threeWayMatches(): HasMany
    {
        return $this->hasMany(ThreeWayMatch::class);
    }

    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }
}
