<?php

namespace App\Models;

use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * What actually arrived, against what was ordered.
 *
 * @property bool $has_shortfall
 * @property Carbon $received_at
 */
#[Fillable([
    'purchase_order_id', 'number', 'delivery_receipt_number',
    'received_at', 'received_by_user_id', 'has_shortfall', 'remarks',
])]
class ReceivingReport extends Model
{
    private const SERVICE_ONLY = [
        'purchase_order_id', 'number', 'delivery_receipt_number', 'has_shortfall',
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
        static::updating(function (ReceivingReport $report): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($report->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'Receiving report %s: "%s" records what arrived and cannot be changed by a direct update.',
                        $report->number ?? '(new)',
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
            'received_at' => 'datetime',
            'has_shortfall' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<PurchaseOrder, $this>
     */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /**
     * @return HasMany<ReceivingReportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(ReceivingReportLine::class);
    }

    /**
     * @return HasOne<Inspection, $this>
     */
    public function inspection(): HasOne
    {
        return $this->hasOne(Inspection::class);
    }

    /**
     * One receipt is matched once — the unique index on the match says so, and
     * the payables screen offers only receipts that have not been.
     *
     * @return HasOne<ThreeWayMatch, $this>
     */
    public function threeWayMatch(): HasOne
    {
        return $this->hasOne(ThreeWayMatch::class);
    }
}
