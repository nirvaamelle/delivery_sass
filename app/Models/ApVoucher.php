<?php

namespace App\Models;

use App\Domain\Procurement\ApVoucherStatus;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * The payable. The last document before money leaves.
 *
 * Every amount on it is derived — gross from the match, withholding from the
 * rate table, the offset from advances already released — so every amount is
 * refused to a direct update. This is the same guard the purchase order and the
 * receiving report carry, and it matters most here: a voucher whose net can be
 * typed over is a payment instruction with no arithmetic behind it, and the
 * three documents upstream would all still read correctly.
 *
 * @property string $gross_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property ?string $withholding_code The F9 columns come from a schema macro, so they
 *                                     are declared here rather than inferred.
 * @property ?string $withholding_rate
 * @property ?string $withholding_certificate_reference
 * @property string $withholding_amount
 * @property string $advance_offset
 * @property string $net_amount
 * @property ApVoucherStatus $status
 * @property Carbon $raised_at
 * @property ?Carbon $submitted_at
 * @property ?string $payment_reference
 * @property ?Carbon $paid_at
 * @property ?Carbon $cancelled_at
 */
#[Fillable([
    'three_way_match_id', 'vendor_id', 'project_id', 'number',
    'gross_amount', 'withholding_code', 'withholding_rate', 'withholding_amount',
    'withholding_certificate_reference', 'advance_offset', 'net_amount',
    'status', 'raised_at', 'raised_by_user_id',
    'submitted_at', 'payment_reference', 'paid_at', 'paid_by_user_id',
    'cancelled_at', 'cancellation_reason', 'cancelled_by_user_id',
])]
class ApVoucher extends Model
{
    private const SERVICE_ONLY = [
        'three_way_match_id', 'vendor_id', 'project_id', 'number',
        'gross_amount', 'withholding_code', 'withholding_rate', 'withholding_amount',
        'advance_offset', 'net_amount', 'status',
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
        static::updating(function (ApVoucher $voucher): void {
            if (self::$withinService) {
                return;
            }

            foreach (self::SERVICE_ONLY as $column) {
                if ($voucher->isDirty($column)) {
                    throw new DomainException(sprintf(
                        'AP voucher %s: "%s" is computed from the match, the rate table and the advances already released. It cannot be changed by a direct update. Use PayablesService.',
                        $voucher->number ?? '(new)',
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
            'gross_amount' => 'decimal:4',
            'withholding_rate' => 'decimal:6',
            'withholding_amount' => 'decimal:4',
            'advance_offset' => 'decimal:4',
            'net_amount' => 'decimal:4',
            'status' => ApVoucherStatus::class,
            'raised_at' => 'datetime',
            'submitted_at' => 'datetime',
            'paid_at' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<ThreeWayMatch, $this>
     */
    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(ThreeWayMatch::class, 'three_way_match_id');
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
     * @return HasMany<VendorAdvance, $this>
     */
    public function advancesRecovered(): HasMany
    {
        return $this->hasMany(VendorAdvance::class, 'offset_ap_voucher_id');
    }
}
