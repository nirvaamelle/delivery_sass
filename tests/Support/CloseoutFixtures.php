<?php

/*
|--------------------------------------------------------------------------
| Shared close-out fixtures
|--------------------------------------------------------------------------
|
| Written as a shared file on the FIRST close-out task rather than after the
| fatal redeclare, which is the tenth time this build has confronted the rule:
| a function declared in a Pest test file is global to every other test file,
| so the second suite to want the name dies with a redeclare rather than a
| failure.
|
| Phase 5 makes it certain. Back-charges, warranties, the turnover pack, final
| billing, retention release and the close-out checklist all need a project that
| has reached substantial completion with a punchlist against it — six suites
| wanting the same three builders.
|
| They go through the real services throughout. A fixture that inserted rows
| would build state the application itself refuses.
|
*/

use App\Domain\Closeout\BackChargeService;
use App\Domain\Closeout\CloseOutChecklistService;
use App\Domain\Closeout\DemobilizationService;
use App\Domain\Closeout\FinalAccountService;
use App\Domain\Closeout\FinalBillingService;
use App\Domain\Closeout\ProjectCloseoutService;
use App\Domain\Closeout\PunchlistResponsibility;
use App\Domain\Closeout\PunchlistService;
use App\Domain\Closeout\RetentionReleaseService;
use App\Domain\Closeout\SubstantialCompletionService;
use App\Domain\Closeout\TurnoverItemSource;
use App\Domain\Closeout\TurnoverService;
use App\Domain\Closeout\WarrantyService;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Mobilization\PermitService;
use App\Domain\Mobilization\PermitType;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Vendors\ScorecardService;
use App\Models\BackCharge;
use App\Models\Billing;
use App\Models\BillingMilestone;
use App\Models\CashAdvance;
use App\Models\CloseOutChecklistItem;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\Permit;
use App\Models\Project;
use App\Models\Punchlist;
use App\Models\PunchlistItem;
use App\Models\PurchaseOrder;
use App\Models\SalesInvoice;
use App\Models\Subcontract;
use App\Models\SubstantialCompletion;
use App\Models\TurnoverPack;
use App\Models\TurnoverPackItem;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warranty;
use Illuminate\Support\Carbon;

function substantialCompletion(): SubstantialCompletionService
{
    return app(SubstantialCompletionService::class);
}

function punchlists(): PunchlistService
{
    return app(PunchlistService::class);
}

/**
 * A project far enough along to be certified complete.
 *
 * The phase is set deliberately rather than defaulted: the certificate service
 * refuses a project that never reached construction, and a fixture that quietly
 * produced one would hide that refusal from every suite downstream.
 */
function constructionProject(): Project
{
    return Project::factory()->create(['phase' => ProjectPhase::Construction]);
}

/**
 * A project with substantial completion certified.
 *
 * @return array{0: Project, 1: SubstantialCompletion}
 */
function completedProject(): array
{
    $project = constructionProject();

    $certificate = substantialCompletion()->certify(
        $project,
        Carbon::parse('2026-05-18'),
        User::factory()->create(),
        clientRepresentative: 'A. Reyes, Project Director',
    );

    return [$project->fresh(), $certificate];
}

/**
 * A punchlist issued against a certified project, with no items on it yet.
 */
function issuedPunchlist(): Punchlist
{
    [, $certificate] = completedProject();

    return punchlists()->issue($certificate, User::factory()->create(), Carbon::parse('2026-05-20'));
}

/**
 * Works let to a subcontractor on a named project.
 *
 * Takes the project rather than making one, because the attribution tests turn
 * on a subcontract belonging to a DIFFERENT project than the punchlist. Takes
 * the vendor for the same reason in reverse: the warranty suite needs one
 * subcontractor holding works on two projects, so a project mismatch can be
 * tested without a vendor mismatch firing first and masking it.
 */
function subcontractOn(Project $project, string $code = 'SUB-CO', string $amount = '2500000.0000', ?Vendor $vendor = null): Subcontract
{
    return subcontracts()->award(
        $project,
        $vendor ?? bondedSubcon($code),
        'Structural steel erection',
        $amount,
        Carbon::parse('2026-01-15'),
        Carbon::parse('2026-12-31'),
    );
}

function backCharges(): BackChargeService
{
    return app(BackChargeService::class);
}

function finalAccounts(): FinalAccountService
{
    return app(FinalAccountService::class);
}

/**
 * An approved purchase order on a project with nothing delivered against it.
 *
 * Money promised and in no ledger category — the shape the forecast to
 * completion exists to count.
 */
function undeliveredOrderOn(Project $project, string $unitPrice = '124500.0000'): PurchaseOrder
{
    $clock = Carbon::getTestNow();
    Carbon::setTestNow('2026-05-20 09:00:00');

    try {
        $c = canvassed();
        $c['pr']->update(['project_id' => $project->getKey()]);

        $order = purchaseOrders()->raise($c['tabulation'], [
            ['description' => 'Portland cement', 'quantity' => '1.0000', 'unit_price' => $unitPrice, 'unit' => 'bags'],
        ]);

        PurchaseOrder::mutate(fn () => $order->update([
            'project_id' => $project->getKey(),
            'status' => PurchaseOrderStatus::Approved,
        ]));
    } finally {
        Carbon::setTestNow($clock);
    }

    return $order->refresh();
}

function checklists(): CloseOutChecklistService
{
    return app(CloseOutChecklistService::class);
}

function retentionReleases(): RetentionReleaseService
{
    return app(RetentionReleaseService::class);
}

function closeouts(): ProjectCloseoutService
{
    return app(ProjectCloseoutService::class);
}

/**
 * A project billed out in full, with retention on the ledger and the defects
 * liability period behind it.
 *
 * The history is built with the clock wound back to when it happened — the
 * back-charge posts against May's billing cutoff, and a fixture that built it
 * "now" would be refused by F3's calendar for being late. The caller's clock is
 * restored before it returns, because the DLP tests turn on today's date.
 *
 * @return array{project: Project, contract: Contract, milestone: BillingMilestone, billing: Billing, invoice: SalesInvoice, employee: Employee, subcontractor: Vendor}
 */
function retentionHeld(?string $completedOn = '2026-05-18', bool $collectInvoice = true): array
{
    $clock = Carbon::getTestNow();
    Carbon::setTestNow('2026-05-20 09:00:00');

    try {
        billingCalendarForMay();

        $f = finalBillable();
        $user = User::factory()->create();

        $billing = finalBillings()->raise($f['milestone'], finalLines(), $user);
        billings()->approve($billing, Carbon::parse('2026-05-21'));

        $invoice = collections()->invoice($billing->fresh(), Carbon::parse('2026-05-22'));
        retention()->withhold($invoice, $user);

        // P2-09: revenue reaches the ledger at the invoice, at the gross. The
        // final P&L is summed from the ledger, so a fixture that skipped this
        // would report a project that cost money and earned none.
        revenue()->post($invoice);

        if ($collectInvoice) {
            collections()->collect($invoice->fresh(), '2134250.0000', Carbon::parse('2026-05-30'), 'CHK-77120', by: $user);
        }

        // Somebody on site, so the demobilization has a clearance line to sign.
        $employee = contractedEmployeeIn($f['project']->organization()->sole(), 'EMP-RT-'.uniqid());
        workedDays($employee, $f['project'], ['2026-05-04']);

        $f['contract']->update(['completed_on' => $completedOn]);

        $subcontractor = Subcontract::query()
            ->where('project_id', $f['project']->getKey())
            ->sole()
            ->vendor()
            ->sole();
    } finally {
        Carbon::setTestNow($clock);
    }

    // refresh() rather than fresh(): fresh() is nullable and these are the
    // types the close-out suites destructure without checking.
    return [
        'project' => $f['project']->refresh(),
        'contract' => $f['contract']->refresh(),
        'milestone' => $f['milestone'],
        'billing' => $billing->refresh(),
        'invoice' => $invoice->refresh(),
        'employee' => $employee->refresh(),
        'subcontractor' => $subcontractor,
    ];
}

/**
 * A project with nothing left outstanding — or with exactly one thing left, so
 * the close refusals can be told apart.
 *
 * @return array{project: Project, contract: Contract, invoice: SalesInvoice, employee: Employee, subcontractor: Vendor}
 */
function closeableProject(
    bool $collectRetention = true,
    bool $collectInvoice = true,
    bool $demobilize = true,
    bool $checklist = true,
    bool $rateVendors = true,
): array {
    $r = retentionHeld(collectInvoice: $collectInvoice);
    $user = User::factory()->create();

    if ($collectRetention) {
        $claim = retentionReleases()->claim($r['contract'], '242500.0000', $user);
        retentionReleases()->collect($claim, Carbon::parse('2027-07-15'), 'OR-RET-1', $user);
    }

    if ($demobilize) {
        $demobilization = demobilizations()->open($r['project'], $user);
        demobilizations()->clear($demobilization, $r['employee'], $user, 'Tools returned, ID surrendered.');
        demobilizations()->complete($demobilization->fresh(), $user, 'Site handed back.');
    }

    /*
     * Slide 9 puts the scorecards in the close-out, and from P5-09 the final
     * account refuses to be filed over an unrated supplier. Rating goes through
     * the real service so a fixture cannot produce a card the rules would
     * refuse.
     */
    if ($rateVendors) {
        Subcontract::query()
            ->where('project_id', $r['project']->getKey())
            ->get()
            ->each(fn (Subcontract $subcontract) => app(ScorecardService::class)->rateSubcontract($subcontract, $user));
    }

    /*
     * The checklist is signed only when everything else is done, because a line
     * cannot be certified over evidence that is false — which is the whole
     * point of P5-08. A fixture asked for a deliberately incomplete project
     * therefore leaves the checklist unopened, and the suites that want one
     * open it themselves.
     */
    if ($checklist && $collectRetention && $collectInvoice && $demobilize && $rateVendors) {
        finalAccounts()->file($r['project'], $user, 'Final account agreed with the PMO.');

        $signed = checklists()->open($r['project'], $user);

        $signed->items->each(fn (CloseOutChecklistItem $item) => checklists()->clear(
            $signed, $item->document_key, $user, 'Checked against the file and signed off.',
        ));
    }

    return [
        'project' => $r['project']->refresh(),
        'contract' => $r['contract']->refresh(),
        'invoice' => $r['invoice']->refresh(),
        'employee' => $r['employee'],
        'subcontractor' => $r['subcontractor'],
    ];
}

function demobilizations(): DemobilizationService
{
    return app(DemobilizationService::class);
}

/**
 * A project the client has accepted, with somebody who worked on it — and
 * optionally the two things that stop it being released: plant still on site
 * and money advanced against it.
 *
 * @return array{project: Project, pack: TurnoverPack, employee: Employee, equipment: ?Equipment, assignment: ?EquipmentAssignment, advance: ?CashAdvance}
 */
function demobilisable(bool $equipment = false, ?string $advance = null): array
{
    [$pack, $project] = acceptedPack();

    $employee = contractedEmployeeIn($project->organization()->sole(), 'EMP-DM-'.uniqid());
    workedDays($employee, $project, ['2026-05-04']);

    $machine = null;
    $assignment = null;

    if ($equipment) {
        $machine = ownedExcavator(['organization_id' => $project->organization_id, 'code' => 'EQ-DM-'.uniqid()]);
        $assignment = equipment()->assign($machine, $project, Carbon::parse('2026-02-01'));
    }

    $released = $advance === null
        ? null
        : advances()->release($employee, $project, $advance, Carbon::parse('2026-05-04'), 'Site petty cash');

    return [
        'project' => $project->fresh(),
        'pack' => $pack->fresh(),
        'employee' => $employee->fresh(),
        'equipment' => $machine,
        'assignment' => $assignment,
        'advance' => $released,
    ];
}

function finalBillings(): FinalBillingService
{
    return app(FinalBillingService::class);
}

/**
 * The one line a 100% final billing carries: 5% of a 48,500,000 contract.
 *
 * @return array<int, array{line_key: string, description: string, amount: string}>
 */
function finalLines(string $amount = '2425000.0000'): array
{
    return [[
        'line_key' => 'final_100',
        'description' => 'Final billing, 5% of contract sum',
        'amount' => $amount,
    ]];
}

/**
 * A project standing exactly where slide 9 puts step 4: certified, punchlist
 * closed, turnover accepted, and a subcontractor defect priced against it.
 *
 * The flags are the failure paths. `turnover: false` never assembles the pack,
 * `accept: false` leaves it unaccepted, and `priced: false` leaves the cleared
 * defect with no back-charge against it — the hard predecessor unsatisfied.
 *
 * @return array{project: Project, contract: Contract, milestone: BillingMilestone, punchlist: Punchlist, backCharge: ?BackCharge}
 */
function finalBillable(
    bool $turnover = true,
    bool $accept = true,
    bool $priced = true,
    string $backChargeAmount = '48250.0000',
): array {
    [$project, $contract] = contractedProject();
    $project->update(['phase' => ProjectPhase::Construction]);
    $project = $project->fresh();

    $user = User::factory()->create();

    $schedule = schedules()->createFor($contract);
    $milestone = $schedule->milestones()->where('code', 'final_100')->sole();

    foreach (['certificate_of_completion', 'warranty', 'clearances'] as $key) {
        schedules()->attachDocument($milestone->fresh(), $key, strtoupper($key).'-1', $user);
    }

    verifiedAccomplishment($project, '100.00', $user);

    $certificate = substantialCompletion()->certify(
        $project, Carbon::parse('2026-05-18'), $user, clientRepresentative: 'A. Reyes, Project Director',
    );

    $punchlist = punchlists()->issue($certificate, $user, Carbon::parse('2026-05-20'));

    $subcontract = subcontractOn($project, 'SUB-FIN-'.uniqid());
    $item = punchlists()->addItem(
        $punchlist,
        'Steel handrail out of plumb',
        location: 'Roof deck',
        responsibility: PunchlistResponsibility::Subcontractor,
        subcontract: $subcontract,
    );
    punchlists()->clear($item, $user, 'Re-set and re-welded by own forces; re-inspected.');

    $backCharge = $priced
        ? backCharges()->raise($item->fresh(), remedialCostCode($punchlist), $backChargeAmount, $user, 'Handrail re-set by own forces.')
        : null;

    punchlists()->close($punchlist->fresh(), $user, 'Site walked with the client; no outstanding defects.');

    if ($turnover) {
        $pack = turnovers()->assemble($certificate, $user);
        fileEveryDocument($pack);
        occupancyPermitFor($project);
        warranties()->register(
            $project, $subcontract->vendor()->sole(), 'CERT-'.uniqid(), 'Structural steel',
            Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), $user, subcontract: $subcontract,
        );

        if ($accept) {
            turnovers()->accept($pack->fresh(), $user, 'A. Reyes, Project Director');
        }
    }

    return [
        'project' => $project->fresh(),
        'contract' => $contract->fresh(),
        'milestone' => $milestone->fresh(),
        'punchlist' => $punchlist->fresh(),
        'backCharge' => $backCharge,
    ];
}

function turnovers(): TurnoverService
{
    return app(TurnoverService::class);
}

/**
 * A certified project with a punchlist issued against it — the state slide 9
 * puts immediately before turnover.
 *
 * @return array{0: Project, 1: SubstantialCompletion, 2: Punchlist}
 */
function turnoverProject(): array
{
    [$project, $certificate] = completedProject();

    $punchlist = punchlists()->issue($certificate, User::factory()->create(), Carbon::parse('2026-05-20'));

    return [$project, $certificate, $punchlist];
}

/**
 * A turnover pack assembled and entirely outstanding.
 *
 * @return array{0: TurnoverPack, 1: Project, 2: Punchlist}
 */
function assembledPack(): array
{
    [$project, $certificate, $punchlist] = turnoverProject();

    return [turnovers()->assemble($certificate, User::factory()->create()), $project, $punchlist];
}

/**
 * File every line the template expects a human to file.
 *
 * The derived lines are deliberately not here — they cannot be filed at all,
 * which is the distinction the suite is about.
 */
function fileEveryDocument(TurnoverPack $pack): void
{
    $filer = User::factory()->create();

    $pack->items()
        ->where('source', TurnoverItemSource::Filed)
        ->get()
        ->each(fn (TurnoverPackItem $item) => turnovers()->file(
            $pack, $item->document_key, strtoupper(substr($item->document_key, 0, 3)).'-2026-004', $filer,
        ));
}

/**
 * The permit that answers the pack's permit line.
 */
function occupancyPermitFor(Project $project): Permit
{
    return app(PermitService::class)->register(
        $project,
        PermitType::OccupancyPermit,
        'OCC-2026-'.uniqid(),
        'Office of the Building Official',
        Carbon::parse('2026-05-01'),
        Carbon::parse('2031-05-01'),
    );
}

/**
 * A pack with nothing outstanding — every document filed, both registers
 * answered, and the punchlist closed unless the caller wants it open.
 *
 * @return array{0: TurnoverPack, 1: Project, 2: Punchlist}
 */
function completePack(bool $closePunchlist = true): array
{
    [$pack, $project, $punchlist] = assembledPack();

    fileEveryDocument($pack);
    occupancyPermitFor($project);

    if ($closePunchlist) {
        punchlists()->close($punchlist, User::factory()->create(), 'Site walked with the client; no outstanding defects.');
    }

    return [$pack, $project, $punchlist->fresh()];
}

/**
 * A pack the client has signed for.
 *
 * @return array{0: TurnoverPack, 1: Project}
 */
function acceptedPack(): array
{
    [$pack, $project] = completePack();

    return [turnovers()->accept($pack, User::factory()->create(), 'A. Reyes, Project Director'), $project];
}

function warranties(): WarrantyService
{
    return app(WarrantyService::class);
}

/**
 * A project and a supplier who could hold either a purchase order or a
 * subcontract against it.
 *
 * Bonded rather than merely accredited, because the same vendor is used for the
 * subcontract-scoped tests and the award service refuses one without a bond.
 *
 * @return array{0: Project, 1: Vendor}
 */
function warrantyParties(string $code = 'WTY-V'): array
{
    return [constructionProject(), bondedSubcon($code)];
}

/**
 * A certificate on file, covering a year from substantial completion.
 */
function registeredWarranty(
    ?Project $project = null,
    ?Vendor $vendor = null,
    string $reference = 'ACME-WC-8841',
    string $scope = 'Roof membrane, materials and workmanship',
    string $startsOn = '2026-05-18',
    string $endsOn = '2027-05-18',
): Warranty {
    if ($project === null && $vendor === null) {
        [$project, $vendor] = warrantyParties();
    }

    return warranties()->register(
        $project ?? constructionProject(),
        $vendor ?? bondedSubcon('WTY-V-'.uniqid()),
        $reference,
        $scope,
        Carbon::parse($startsOn),
        Carbon::parse($endsOn),
        User::factory()->create(),
    );
}

/**
 * An open billing calendar for May 2026.
 *
 * Back-charge cost is direct project cost and books against the BILLING
 * calendar, the same one material issues use. Without it the ledger refuses the
 * posting for want of a calendar, which is F3 working — but it is not what the
 * back-charge suite is testing.
 */
function billingCalendarForMay(): void
{
    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Billing,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => '2026-12-31 17:00:00',
    ]);
}

/**
 * A punchlist with a subcontractor working on the same project.
 *
 * @return array{0: Punchlist, 1: Subcontract}
 */
function punchlistWithSubcontract(string $amount = '2500000.0000'): array
{
    $punchlist = issuedPunchlist();

    return [$punchlist, subcontractOn($punchlist->project()->sole(), 'SUB-CO', $amount)];
}

/**
 * A cost code the remedial work can be charged to.
 */
function remedialCostCode(Punchlist $punchlist): CostCode
{
    return CostCode::factory()->create([
        'organization_id' => $punchlist->project()->sole()->organization_id,
    ]);
}

/**
 * A subcontractor defect that has been made good — the state a back-charge may
 * be raised against, and only that state.
 *
 * @return array{0: PunchlistItem, 1: Subcontract, 2: CostCode}
 */
function clearedSubcontractorItem(string $subcontractAmount = '2500000.0000'): array
{
    [$punchlist, $subcontract] = punchlistWithSubcontract($subcontractAmount);

    $item = punchlists()->addItem(
        $punchlist,
        'Steel handrail out of plumb',
        location: 'Roof deck',
        responsibility: PunchlistResponsibility::Subcontractor,
        subcontract: $subcontract,
    );

    punchlists()->clear($item, User::factory()->create(), 'Re-set and re-welded by own forces; re-inspected.');

    return [$item->fresh(), $subcontract, remedialCostCode($punchlist)];
}
