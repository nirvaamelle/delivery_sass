<?php

namespace App\Domain\Vendors;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorAccreditation;
use App\Models\VendorBond;
use App\Models\VendorValidationVisit;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vendor accreditation and standing — PLAN.md §5, closes F11.
 *
 *   "Accreditation expires at 12 months; expired vendors cannot receive an RFQ."
 *   "Two late deliveries in a quarter, or a rejection rate above 5%, suspends a
 *    vendor. Falsified documents — immediate removal."
 *
 * Eligibility is **derived on read**, never stored as a flag. A stored
 * `is_expired` column is only as true as the last scheduled job that ran, and
 * the day that job silently fails is the day an expired vendor receives an RFQ.
 * Computing it costs a date comparison; getting it wrong costs an audit finding.
 *
 * Accreditations append rather than overwrite, so the question an auditor
 * actually asks — "was this vendor accredited on the day that PO was raised?" —
 * stays answerable years later.
 *
 * PLACEHOLDER: Part D item 17 — which role may clear a suspension, and whether
 * clearing resets the scorecard window, is unanswered. The clearing user is
 * recorded; who is *permitted* to clear becomes a permission check when the
 * client answers.
 */
class VendorService
{
    /**
     * PLAN.md §5: accreditation runs twelve months.
     */
    private const ACCREDITATION_MONTHS = 12;

    /**
     * Accredit a vendor, or renew an existing accreditation.
     */
    public function accredit(
        Vendor $vendor,
        CarbonInterface $from,
        ?string $certificateReference = null,
        ?User $by = null,
    ): VendorAccreditation {
        return DB::transaction(function () use ($vendor, $from, $certificateReference, $by): VendorAccreditation {
            $accreditation = $vendor->accreditations()->create([
                'accredited_at' => $from,
                'expires_at' => $from->copy()->addMonths(self::ACCREDITATION_MONTHS),
                'certificate_reference' => $certificateReference,
                'accredited_by_user_id' => $by?->getKey(),
            ]);

            // A removed vendor stays removed. Accrediting one would be a way
            // back from a terminal state through the side door.
            if ($vendor->status !== VendorStatus::Removed) {
                $vendor->update([
                    'status' => VendorStatus::Accredited,
                    'status_reason' => null,
                    'status_changed_by_user_id' => $by?->getKey(),
                    'status_changed_at' => now(),
                ]);
            }

            return $accreditation;
        });
    }

    /**
     * May this vendor be treated as accredited right now?
     *
     * Both conditions must hold: standing AND an unexpired certificate. A live
     * certificate does not outrank a suspension — if it did, suspending a
     * vendor would change nothing about what they can be sent, which is the
     * whole purpose of suspending them.
     */
    public function isAccredited(Vendor $vendor, ?CarbonInterface $asOf = null): bool
    {
        if ($vendor->status !== VendorStatus::Accredited) {
            return false;
        }

        $asOf ??= now();

        return $vendor->accreditations()
            ->whereDate('accredited_at', '<=', $asOf)
            // Inclusive: a vendor accredited exactly twelve months ago is still
            // current on the anniversary itself. Somebody will stand on that
            // day, so which side they land on is decided here rather than by
            // an accident of comparison operator.
            ->whereDate('expires_at', '>=', $asOf)
            ->exists();
    }

    /**
     * Every vendor currently able to receive an RFQ.
     *
     * This is what P1-03's RFQ gate consults. Kept here rather than as a query
     * scope so the eligibility rule has exactly one definition.
     *
     * @return Collection<int, Vendor>
     */
    public function eligibleForRfq(?CarbonInterface $asOf = null): Collection
    {
        $asOf ??= now();

        return Vendor::query()
            ->where('status', VendorStatus::Accredited)
            ->whereHas('accreditations', fn ($query) => $query
                ->whereDate('accredited_at', '<=', $asOf)
                ->whereDate('expires_at', '>=', $asOf))
            ->orderBy('code')
            ->get();
    }

    /**
     * Record a validation visit - F15.
     *
     * Somebody went to the address on the accreditation form and reported what
     * was there. Every visit is kept: a vendor that failed one and passed the
     * next has a history worth reading, and overwriting keeps only the
     * flattering half.
     */
    public function recordValidationVisit(
        Vendor $vendor,
        CarbonInterface $visitedAt,
        ValidationVerdict $verdict,
        ?User $by = null,
        ?string $findings = null,
    ): VendorValidationVisit {
        return $vendor->validationVisits()->create([
            'visited_at' => $visitedAt,
            'verdict' => $verdict,
            'findings' => $findings,
            'visited_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * The most recent visit, if there has been one.
     */
    public function latestValidationVisit(Vendor $vendor): ?VendorValidationVisit
    {
        return $vendor->validationVisits()
            ->orderByDesc('visited_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Register a bond - F15.
     *
     * Renewals append, like accreditations, so a lapsed bond and the one that
     * replaced it both remain on file.
     */
    public function registerBond(
        Vendor $vendor,
        BondType $type,
        string $amount,
        CarbonInterface $effectiveAt,
        CarbonInterface $expiresAt,
        ?string $reference = null,
        ?string $issuer = null,
    ): VendorBond {
        return $vendor->bonds()->create([
            'type' => $type,
            'amount' => $amount,
            'effective_at' => $effectiveAt,
            'expires_at' => $expiresAt,
            'reference' => $reference,
            'issuer' => $issuer,
        ]);
    }

    /**
     * Does this vendor currently hold a valid bond of this type?
     *
     * Typed deliberately: a surety bond is not a performance bond, and
     * accepting either in place of the other defeats the point of having asked
     * for a specific one.
     *
     * Read entirely separately from accreditation. F15 exists because the two
     * clocks are independent - a current accreditation with a lapsed
     * performance bond is exactly the combination that gets a subcontract
     * awarded against no cover.
     */
    public function hasValidBond(Vendor $vendor, BondType $type, ?CarbonInterface $asOf = null): bool
    {
        $asOf ??= now();

        return $vendor->bonds()
            ->where('type', $type)
            ->whereDate('effective_at', '<=', $asOf)
            ->whereDate('expires_at', '>=', $asOf)
            ->exists();
    }

    /**
     * Suspend a vendor, recording why and who decided.
     */
    public function suspend(
        Vendor $vendor,
        VendorSuspensionReason $reason,
        User $by,
        ?string $notes = null,
    ): Vendor {
        return $this->changeStatus($vendor, VendorStatus::Suspended, $reason, $by, $notes);
    }

    /**
     * Lift a suspension.
     *
     * @throws DomainException when the vendor was removed rather than suspended
     */
    public function clearSuspension(Vendor $vendor, User $by, ?string $notes = null): Vendor
    {
        if ($vendor->status === VendorStatus::Removed) {
            throw new DomainException(
                'A removed vendor cannot be cleared. PLAN.md §5 makes removal for falsified documents terminal.'
            );
        }

        return $this->changeStatus($vendor, VendorStatus::Accredited, null, $by, $notes);
    }

    /**
     * Remove a vendor permanently — falsified documents, per PLAN.md §5.
     */
    public function remove(Vendor $vendor, User $by, ?string $notes = null): Vendor
    {
        return $this->changeStatus(
            $vendor,
            VendorStatus::Removed,
            VendorSuspensionReason::FalsifiedDocuments,
            $by,
            $notes,
        );
    }

    private function changeStatus(
        Vendor $vendor,
        VendorStatus $status,
        ?VendorSuspensionReason $reason,
        User $by,
        ?string $notes,
    ): Vendor {
        $vendor->update([
            'status' => $status,
            'status_reason' => $reason,
            'status_notes' => $notes,
            'status_changed_by_user_id' => $by->getKey(),
            'status_changed_at' => now(),
        ]);

        return $vendor->refresh();
    }
}
