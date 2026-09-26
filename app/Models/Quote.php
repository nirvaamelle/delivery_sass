<?php

namespace App\Models;

use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A vendor's response to an RFQ.
 *
 * `total_amount` is the number the award is decided on, so it is derived from
 * the lines and guarded against direct writes. A hand-entered total is a
 * hand-entered outcome, and it would not match the lines printed beneath it on
 * the abstract of canvass.
 *
 * @property string $total_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property Carbon $quoted_at
 */
#[Fillable(['rfq_id', 'vendor_id', 'number', 'total_amount', 'quoted_at', 'validity_days', 'remarks'])]
class Quote extends Model
{
    private const SERVICE_ONLY = ['rfq_id', 'vendor_id', 'number', 'total_amount'];

    private static bool $withinService = false;

    /**
     * Run a write allowed to touch the guarded columns.
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
        static::updating(function (Quote $quote): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($quote->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'Quote %s: "%s" is derived from the quote lines and cannot be set directly. Use QuoteService.',
                        $quote->number ?? '(new)',
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
            'total_amount' => 'decimal:4',
            'quoted_at' => 'date',
            'validity_days' => 'integer',
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
     * @return BelongsTo<Vendor, $this>
     */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /**
     * @return HasMany<QuoteLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class);
    }
}
