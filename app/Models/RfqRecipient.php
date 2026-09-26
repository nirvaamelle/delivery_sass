<?php

namespace App\Models;

use App\Domain\Procurement\RfqStatus;
use App\Domain\Procurement\VendorNotEligibleException;
use App\Domain\Vendors\VendorService;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One vendor invited to quote.
 *
 * The eligibility check lives in RfqService::invite(), but the relation is
 * reachable from anywhere — `$rfq->recipients()->create([...])` skips the
 * service entirely. Found by security review of P1-03: the control that PLAN.md
 * §5 states ("expired vendors cannot receive an RFQ") held on one path and not
 * on the other, which is the exact shape of failure §5 warns about, since a
 * queued job or an importer never calls the service method.
 *
 * The guard is repeated here rather than moved here. The service is still where
 * the rule is explained and where a caller should go; this is the backstop that
 * makes the rule true of every write.
 */
#[Fillable(['rfq_id', 'vendor_id', 'sent_at', 'responded_at'])]
class RfqRecipient extends Model
{
    protected static function booted(): void
    {
        static::creating(function (RfqRecipient $recipient): void {
            $rfq = $recipient->rfq()->first();

            // The recipient list is what the three-quote minimum was measured
            // against. Adding to it after issue makes that check describe a
            // list that no longer exists.
            if ($rfq !== null && $rfq->status !== RfqStatus::Draft) {
                throw new DomainException(sprintf(
                    'RFQ %s is %s; its recipient list is closed.',
                    $rfq->number,
                    $rfq->status->value,
                ));
            }

            $vendor = $recipient->vendor()->first();

            if ($vendor === null) {
                throw new VendorNotEligibleException(
                    'An RFQ recipient must name a vendor that exists.'
                );
            }

            if (! app(VendorService::class)->isAccredited($vendor)) {
                throw new VendorNotEligibleException(sprintf(
                    'Vendor %s cannot receive an RFQ: accreditation is not current.',
                    $vendor->code,
                ));
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
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
}
