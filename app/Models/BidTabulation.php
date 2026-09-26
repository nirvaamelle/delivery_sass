<?php

namespace App\Models;

use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * The abstract of canvass: what was compared, and what is recommended.
 *
 * The recommendation and the count of quotes compared are the record of a
 * decision, so both are guarded against direct writes. Editing the recommended
 * vendor afterwards leaves a document asserting that a comparison chose
 * something it did not.
 *
 * @property string $recommended_amount DECIMAL(18,4), surfaced as a string.
 * @property int $quotes_compared
 * @property Carbon $tabulated_at
 */
#[Fillable([
    'rfq_id', 'number', 'recommended_vendor_id', 'recommended_amount',
    'quotes_compared', 'sole_source', 'tabulated_at', 'tabulated_by_user_id', 'remarks',
])]
class BidTabulation extends Model
{
    private const SERVICE_ONLY = [
        'rfq_id', 'number', 'recommended_vendor_id',
        'recommended_amount', 'quotes_compared', 'sole_source',
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
        static::updating(function (BidTabulation $tabulation): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($tabulation->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'Tabulation %s: "%s" records what the canvass compared and cannot be changed by a direct update.',
                        $tabulation->number ?? '(new)',
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
            'recommended_amount' => 'decimal:4',
            'quotes_compared' => 'integer',
            'sole_source' => 'boolean',
            'tabulated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Rfq, $this>
     */
    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    /**
     * One order per abstract of canvass — the order screen offers only
     * tabulations that have not been awarded yet.
     *
     * @return HasOne<PurchaseOrder, $this>
     */
    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class);
    }

    /**
     * @return BelongsTo<Vendor, $this>
     */
    public function recommendedVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'recommended_vendor_id');
    }
}
