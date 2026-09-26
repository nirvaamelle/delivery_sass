<?php

namespace App\Domain\Closeout;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Vendors\ScorecardService;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subcontract;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warranty;
use App\Models\WarrantyClaim;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The warranty register — F7.
 *
 * The finding: "Warranty certificates are collected at turnover, and an
 * unresolved warranty claim suspends a vendor (slide 5). So warranties need a
 * register that links the certificate to the vendor and to any claim raised
 * against it."
 *
 * Half of that was built a phase early. `warranty_claims` exists because F11's
 * suspension trigger needed something to fire on, and it has been firing since
 * P1-14 against a vendor and an order. What it never had was the certificate:
 * a claim could name the supplier, but not the promise the supplier was being
 * held to.
 *
 * Three decisions carry this service.
 *
 * **Coverage is a period, and the operative date is the failure.** A claim is
 * tested against when the goods failed, never against when the claim was typed
 * up. A compressor that died the day before expiry is covered however long the
 * paperwork took, and a register that checked the filing date would deny it —
 * quietly, and in the vendor's favour.
 *
 * **The claim goes through the same door as before.** Raising one here calls
 * ScorecardService, so the vendor is suspended exactly as F11 requires. A
 * second path that recorded a claim without suspending would disarm the trigger
 * for precisely the well-documented claims — the ones with a certificate behind
 * them.
 *
 * **The certificate stays optional on the claim.** Goods whose warranty nobody
 * collected still fail. Refusing that claim would lose the trigger along with
 * the paperwork, so the link is nullable and the gap is reported instead —
 * `uncoveredSubcontracts()` is that report.
 */
class WarrantyService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly ScorecardService $scorecards,
    ) {}

    /**
     * Enter a certificate in the register.
     *
     * @throws DomainException when the certificate has no reference or no
     *                         scope, the coverage period is inverted, the
     *                         certificate is already registered, or the order
     *                         or subcontract named belongs to somebody else
     */
    public function register(
        Project $project,
        Vendor $vendor,
        string $certificateReference,
        string $scope,
        CarbonInterface $startsOn,
        CarbonInterface $endsOn,
        User $by,
        ?PurchaseOrder $order = null,
        ?Subcontract $subcontract = null,
        ?CarbonInterface $receivedAt = null,
        string $remarks = '',
    ): Warranty {
        $certificateReference = trim($certificateReference);
        $scope = trim($scope);

        if ($certificateReference === '') {
            throw new DomainException(
                "A warranty needs the vendor's own certificate reference. Without it the register records that somebody remembers a warranty existing, which is not something that can be produced at a claim."
            );
        }

        if ($scope === '') {
            throw new DomainException(
                'A warranty must say what it covers. Scope is what a claim is argued against, and "warranty, 1 year" covers whatever the vendor later says it covers.'
            );
        }

        if ($endsOn->copy()->startOfDay()->isBefore($startsOn->copy()->startOfDay())) {
            throw new DomainException(sprintf(
                'Coverage ends before it starts (%s to %s). An inverted period is in force on no date at all.',
                $startsOn->toDateString(),
                $endsOn->toDateString(),
            ));
        }

        if ($order !== null) {
            $this->assertBelongsToBoth(
                (int) $order->vendor_id, (int) $order->project_id, $vendor, $project,
                sprintf('Purchase order %s', $order->number), 'was not placed with',
            );
        }

        if ($subcontract !== null) {
            $this->assertBelongsToBoth(
                (int) $subcontract->vendor_id, (int) $subcontract->project_id, $vendor, $project,
                sprintf('Subcontract %s', $subcontract->number), 'was not let to',
            );
        }

        $duplicate = Warranty::query()
            ->where('vendor_id', $vendor->getKey())
            ->where('certificate_reference', $certificateReference)
            ->first();

        if ($duplicate !== null) {
            // Two rows for one certificate is two expiry dates for one promise,
            // and the turnover pack counts the certificate twice.
            throw new DomainException(sprintf(
                'Certificate %s is already registered for %s as %s.',
                $certificateReference,
                $vendor->code,
                $duplicate->number,
            ));
        }

        return Warranty::query()->create([
            'project_id' => $project->getKey(),
            'vendor_id' => $vendor->getKey(),
            'purchase_order_id' => $order?->getKey(),
            'subcontract_id' => $subcontract?->getKey(),
            'number' => $this->numbering->next('WTY'),
            'certificate_reference' => $certificateReference,
            'scope' => $scope,
            'starts_on' => $startsOn->copy()->startOfDay(),
            'ends_on' => $endsOn->copy()->startOfDay(),
            // When the paper arrived, which is not when coverage began.
            'received_at' => $receivedAt ?? now(),
            'received_by_user_id' => $by->getKey(),
            'remarks' => trim($remarks) === '' ? null : trim($remarks),
        ]);
    }

    /**
     * Claim under a registered certificate.
     *
     * @throws DomainException when the failure is dated forward or falls
     *                         outside the coverage period
     */
    public function claim(
        Warranty $warranty,
        string $description,
        User $by,
        ?CarbonInterface $failedOn = null,
    ): WarrantyClaim {
        $failedOn = ($failedOn ?? now())->copy()->startOfDay();

        if ($failedOn->isAfter(now())) {
            // A claim for a failure that has not happened yet is a forecast,
            // and it would suspend a vendor on one.
            throw new DomainException(sprintf(
                'A failure dated %s has not happened yet.',
                $failedOn->toDateString(),
            ));
        }

        if (! $this->inForceOn($warranty, $failedOn)) {
            throw new DomainException(sprintf(
                'Warranty %s was not in force on %s — it covers %s to %s.',
                $warranty->number,
                $failedOn->toDateString(),
                $warranty->starts_on->toDateString(),
                $warranty->ends_on->toDateString(),
            ));
        }

        // Through ScorecardService, not around it. That call is where F11's
        // suspension lives, and a claim raised here suspends the vendor for the
        // same reason a claim raised anywhere else does.
        return $this->scorecards->raiseWarrantyClaim(
            $warranty->vendor()->sole(),
            $warranty->purchaseOrder,
            $description,
            $by,
            warranty: $warranty,
            failedOn: $failedOn,
        );
    }

    /**
     * Was the promise good on this date? Both ends inclusive — a certificate
     * expiring on the 18th covers the 18th.
     */
    public function inForceOn(Warranty $warranty, CarbonInterface $date): bool
    {
        $day = $date->copy()->startOfDay();

        return ! $day->isBefore($warranty->starts_on->copy()->startOfDay())
            && ! $day->isAfter($warranty->ends_on->copy()->startOfDay());
    }

    /**
     * Claims still open against a certificate.
     *
     * @return Collection<int, WarrantyClaim>
     */
    public function openClaims(Warranty $warranty): Collection
    {
        return $warranty->claims()->whereNull('resolved_at')->orderBy('raised_at')->get();
    }

    /**
     * Certificates still in force that lapse on or before a date.
     *
     * What the close-out report and the defects liability review are read from:
     * the promises about to run out while somebody can still make a claim under
     * them. Already-lapsed certificates are not listed — there is nothing left
     * to do about those.
     *
     * @return Collection<int, Warranty>
     */
    public function expiringBy(Project $project, CarbonInterface $date): Collection
    {
        return Warranty::query()
            ->where('project_id', $project->getKey())
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->whereDate('ends_on', '<=', $date->toDateString())
            ->orderBy('ends_on')
            ->orderBy('id')
            ->get();
    }

    /**
     * Subcontracts on a project with no certificate in the register.
     *
     * "Collected at turnover" as a query, and the counterpart of P5-02's
     * `unpricedItems()`: a subcontract with no warranty on file is a
     * certificate nobody chased, and the turnover pack is entitled to refuse to
     * close over it.
     *
     * @return Collection<int, Subcontract>
     */
    public function uncoveredSubcontracts(Project $project): Collection
    {
        return Subcontract::query()
            ->where('project_id', $project->getKey())
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('warranties')
                ->whereColumn('warranties.subcontract_id', 'subcontracts.id'))
            ->orderBy('number')
            ->get();
    }

    /**
     * The certificate is evidence against a particular purchase, so the
     * purchase has to be this vendor's and on this project. Vendor first: a
     * document belonging to another supplier entirely is the coarser error, and
     * reporting the project mismatch for it would mislead.
     */
    private function assertBelongsToBoth(
        int $documentVendorId,
        int $documentProjectId,
        Vendor $vendor,
        Project $project,
        string $subject,
        string $verb,
    ): void {
        if ($documentVendorId !== $vendor->getKey()) {
            throw new DomainException(sprintf('%s %s %s.', $subject, $verb, $vendor->code));
        }

        if ($documentProjectId !== $project->getKey()) {
            throw new DomainException(sprintf(
                "%s belongs to another project. A certificate registered across projects puts one job's coverage on another job's turnover pack.",
                $subject,
            ));
        }
    }
}
