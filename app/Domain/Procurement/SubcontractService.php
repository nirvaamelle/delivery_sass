<?php

namespace App\Domain\Procurement;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Support\Money;
use App\Domain\Vendors\BondType;
use App\Domain\Vendors\VendorService;
use App\Models\Project;
use App\Models\Subcontract;
use App\Models\Vendor;
use App\Models\VendorBond;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Subcontract awards — F15's first clause.
 *
 *   "Expired bond blocks a subcontract award."
 *
 * A subcontract is not a purchase. The exposure is performance over months, so
 * the document that has to be current is the **performance bond**, not the
 * accreditation certificate — and P1-02 gave bonds their own expiry precisely
 * so a vendor can be currently accredited and currently unbonded. This is where
 * that distinction stops being academic.
 *
 * The bond must also **outlast the works**. A bond lapsing mid-contract covers
 * the easy half: the exposure is largest at the end, when the work is late and
 * the money is largely spent.
 */
class SubcontractService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly VendorService $vendors,
    ) {}

    /**
     * Award works to a subcontractor.
     *
     * @throws VendorNotEligibleException when the subcontractor is not accredited
     * @throws ExpiredBondException when no performance bond covers the works period
     * @throws InvalidArgumentException when the amount or the period is not sane
     */
    public function award(
        Project $project,
        Vendor $vendor,
        string $scopeOfWork,
        string $contractAmount,
        CarbonInterface $worksStart,
        CarbonInterface $worksEnd,
    ): Subcontract {
        if ($worksEnd->lessThan($worksStart)) {
            throw new InvalidArgumentException(
                'A subcontract cannot end before it starts.'
            );
        }

        if (Money::isZero($contractAmount)) {
            throw new InvalidArgumentException(
                'A subcontract with no value awards nothing.'
            );
        }

        // Accreditation and bonding are checked independently, because they say
        // different things. A live bond does not rehabilitate a vendor
        // suspended for late deliveries.
        if (! $this->vendors->isAccredited($vendor)) {
            throw new VendorNotEligibleException(sprintf(
                'Vendor %s (%s) cannot be awarded a subcontract: accreditation is not current.',
                $vendor->code,
                $vendor->status->value,
            ));
        }

        $bond = $this->coveringBond($vendor, $worksEnd);

        return DB::transaction(fn (): Subcontract => Subcontract::mutate(
            fn (): Subcontract => Subcontract::query()->create([
                'project_id' => $project->getKey(),
                'vendor_id' => $vendor->getKey(),
                'number' => $this->numbering->next('SC'),
                'status' => SubcontractStatus::Awarded,
                'scope_of_work' => $scopeOfWork,
                'contract_amount' => $contractAmount,
                'works_start' => $worksStart,
                'works_end' => $worksEnd,
                'performance_bond_id' => $bond->getKey(),
            ])
        ));
    }

    /**
     * The performance bond covering the whole works period.
     *
     * @throws ExpiredBondException
     */
    private function coveringBond(Vendor $vendor, CarbonInterface $worksEnd): VendorBond
    {
        // Specifically a performance bond. A surety bond guarantees the bid and
        // says nothing about whether the work gets finished, so accepting one
        // here would leave the actual exposure uncovered.
        $bond = $vendor->bonds()
            ->where('type', BondType::Performance)
            ->whereDate('effective_at', '<=', now())
            ->whereDate('expires_at', '>=', $worksEnd)
            ->orderByDesc('expires_at')
            ->first();

        if ($bond === null) {
            throw new ExpiredBondException(sprintf(
                'Vendor %s holds no performance bond covering works through %s. F15 blocks the award: the bond, not the accreditation, is what covers a subcontract.',
                $vendor->code,
                $worksEnd->toDateString(),
            ));
        }

        return $bond;
    }
}
