<?php

namespace Database\Seeders;

use App\Domain\Billing\AccomplishmentService;
use App\Domain\Billing\ArAgingService;
use App\Domain\Billing\BillingScheduleService;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\CollectionService;
use App\Domain\Billing\RetentionService;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Equipment\DepreciationService;
use App\Domain\Equipment\EquipmentCostType;
use App\Domain\Equipment\EquipmentService;
use App\Domain\Equipment\Ownership;
use App\Domain\Hris\DisbursementMethod;
use App\Domain\Hris\DisbursementService;
use App\Domain\Hris\EmployeeService;
use App\Domain\Hris\HiringService;
use App\Domain\Hris\OvertimeService;
use App\Domain\Hris\OvertimeType;
use App\Domain\Hris\PayBasis;
use App\Domain\Hris\PayrollService;
use App\Domain\Hris\PayrollVarianceService;
use App\Domain\Hris\TimekeepingService;
use App\Domain\Mobilization\ChecklistItem;
use App\Domain\Mobilization\MobilizationService;
use App\Domain\Mobilization\PermitService;
use App\Domain\Mobilization\PermitType;
use App\Domain\Opex\CashAdvanceService;
use App\Domain\Opex\ExpenseService;
use App\Domain\Opex\OpexCalendarService;
use App\Domain\Opex\OpexStage;
use App\Domain\Opex\OverheadPoster;
use App\Domain\Ops\DemoSeedGuard;
use App\Domain\Posting\LaborCostPoster;
use App\Domain\Posting\MaterialCostPoster;
use App\Domain\Posting\RevenuePoster;
use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\PayablesService;
use App\Domain\Procurement\PurchaseOrderService;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\ReceivingService;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\StockService;
use App\Domain\Procurement\TabulationService;
use App\Domain\Procurement\ThreeWayMatchService;
use App\Domain\Projects\ProjectPhase;
use App\Domain\Requisitions\RequisitionService;
use App\Domain\Vendors\ScorecardService;
use App\Domain\Vendors\ValidationVerdict;
use App\Domain\Vendors\VendorService;
use App\Models\Billing;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\DailyTimeRecord;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

/**
 * One complete procurement cycle, seeded through the real services.
 *
 * Every row this writes goes through the same gates the application enforces —
 * the F14 project gate, the budget check, the accreditation check on an RFQ
 * recipient, the three-quote minimum, the two-predecessor gate on a PO, the
 * three-way match. **A seeder that inserted rows directly would produce a demo
 * of a chain the application itself would have refused to create**, which is the
 * one kind of demo worse than none.
 *
 * It is deliberately not a tidy cycle. The delivery is SHORT — 480 of 500 bags —
 * and twenty of what arrived is rejected at inspection, because the screens
 * exist to make exactly that visible, and a seed where everything went well
 * demonstrates none of it.
 *
 * Refused in production by DemoSeedGuard (P6-08), the guard PHASE-PLAN.md
 * Phase 6 promised here and which, until then, existed only in this comment.
 */
class DemoChainSeeder extends Seeder
{
    public function run(): void
    {
        // Also guarded here, not only in DatabaseSeeder: `db:seed --class=` runs
        // this directly and never passes through the parent.
        app(DemoSeedGuard::class)->assertMaySeed((string) app()->environment());

        $admin = User::query()->firstOrFail();
        $organization = Organization::firstOrCreate(['code' => 'MBI'], ['name' => 'MBI Construction']);

        [$project, $costCode, $issueCode] = $this->project($organization);

        $this->calendars();

        $vendor = Vendor::query()->where('code', 'VEN-0001')->firstOrFail();

        app(VendorService::class)->recordValidationVisit(
            $vendor,
            now()->subMonths(2),
            ValidationVerdict::Passed,
            $admin,
            'Plant visited. Batching equipment and stockpiles as described.',
        );

        $requisition = $this->requisition($project, $costCode);
        $tabulation = $this->canvass($requisition, $vendor);
        $order = $this->order($tabulation, $vendor);

        $report = $this->deliver($order, $admin);
        $this->store($report, $issueCode, $admin);
        $this->pay($order, $report, $admin);

        app(ScorecardService::class)->rate($order->fresh(), $admin);

        $this->equipment($organization, $project, $costCode, $admin);

        // Phase 2: the acquisition-to-cash half of the same project.
        $contract = $project->contracts()->sole();

        $this->mobilize($project, $order, $admin);
        $this->bill($project, $contract, $admin);

        // Phase 3: one semi-monthly cutoff for the same project.
        $this->payroll($organization, $project, $costCode, $admin);

        // Phase 4: one OPEX month, taken to the budget review.
        $this->opex($organization, $project, $costCode, $admin);
    }

    /**
     * A project that has cleared F14 — signed contract, opened budget.
     *
     * @return array{0: Project, 1: CostCode, 2: CostCode}
     */
    private function project(Organization $organization): array
    {
        $project = Project::updateOrCreate(
            ['code' => 'MBI-2026-014'],
            [
                'organization_id' => $organization->getKey(),
                'name' => 'Northgate Terminal — Phase 2 Civil Works',
                'phase' => ProjectPhase::Construction,
                'client_name' => 'Northgate Port Authority',
            ],
        );

        Contract::updateOrCreate(
            ['project_id' => $project->getKey()],
            [
                'number' => 'CON-2026-014',
                'status' => ContractStatus::Signed,
                'noa_date' => '2026-01-20',
                'ntp_date' => '2026-02-01',
                'contract_sum' => '48500000.0000',
                'retention_rate' => '10.00',
                'defects_liability_days' => 365,
                'signed_at' => '2026-01-28 10:00:00',
            ],
        );

        $costCode = CostCode::updateOrCreate(
            ['organization_id' => $organization->getKey(), 'code' => '02.10.100'],
            ['name' => 'Concrete works — materials'],
        );

        $issueCode = CostCode::updateOrCreate(
            ['organization_id' => $organization->getKey(), 'code' => '02.10.140'],
            ['name' => 'Concrete works — pier caps'],
        );

        $budget = Budget::updateOrCreate(
            ['project_id' => $project->getKey(), 'name' => 'Original budget'],
            ['status' => BudgetStatus::Open],
        );

        $budget->lines()->updateOrCreate(
            ['cost_code_id' => $costCode->getKey()],
            ['amount' => '2500000.0000'],
        );

        $budget->lines()->updateOrCreate(
            ['cost_code_id' => $issueCode->getKey()],
            ['amount' => '900000.0000'],
        );

        return [$project->fresh(), $costCode, $issueCode];
    }

    /**
     * Open periods for both calendars the chain posts against.
     *
     * Three months around today, not a fixed window. The demo documents are
     * dated `now()`, so a hard-coded May would leave a seeded chain that fails
     * for no reason the reader can see the moment the month turns — which is
     * precisely the F3 failure mode, arriving in the seeder instead of in
     * production.
     */
    private function calendars(): void
    {
        foreach ([CutoffType::Billing, CutoffType::Opex] as $type) {
            foreach ([-1, 0, 1] as $offset) {
                $month = now()->copy()->addMonths($offset)->startOfMonth();

                CutoffCalendar::query()->updateOrCreate(
                    [
                        'project_id' => null,
                        'cutoff_type' => $type,
                        'period_start' => $month->toDateString(),
                    ],
                    [
                        'period_end' => $month->copy()->endOfMonth()->toDateString(),
                        /*
                         * Deliberately left open. A real calendar closes ten days
                         * after month end, and the demo's own documents are dated
                         * back far enough that the previous month would already be
                         * shut — so a faithful cutoff would make the seed fail on
                         * a control that is working exactly as intended. The
                         * control has its own tests; this file exists to give the
                         * screens something to show.
                         */
                        'cutoff_at' => now()->addYear(),
                    ],
                );
            }
        }

        /*
         * Payroll is SEMI-monthly — F3's whole point, that three chains close on
         * three different rhythms and one calendar cannot answer for all of them.
         * Each month contributes two periods, 1-15 and 16 to month end.
         */
        foreach ([-1, 0, 1] as $offset) {
            $month = now()->copy()->addMonths($offset)->startOfMonth();

            foreach ([
                [$month->copy(), $month->copy()->addDays(14)],
                [$month->copy()->addDays(15), $month->copy()->endOfMonth()],
            ] as [$start, $end]) {
                CutoffCalendar::query()->updateOrCreate(
                    [
                        'project_id' => null,
                        'cutoff_type' => CutoffType::Payroll,
                        'period_start' => $start->toDateString(),
                    ],
                    [
                        'period_end' => $end->toDateString(),
                        'cutoff_at' => now()->addYear(),
                    ],
                );
            }
        }
    }

    private function requisition(Project $project, CostCode $costCode)
    {
        $requisitions = app(RequisitionService::class);

        $requisition = $requisitions->raise($project, [
            [
                'cost_code_id' => $costCode->getKey(),
                'description' => 'Portland cement, Type 1P — 500 bags',
                'amount' => '124500.0000',
            ],
        ]);

        // Routed by amount through the authority matrix, then signed by whoever
        // the matrix asked for. The seeder does not shortcut the approval: an
        // approved requisition that never went through the router would leave
        // the inbox and the document disagreeing.
        $requisitions->submit($requisition);

        foreach ($requisition->fresh()->approvals()->orderBy('step')->get() as $step) {
            $approver = $this->approver($step->approver_role);
            $requisitions->approve($requisition->fresh(), $step, $approver);
        }

        return $requisition->fresh();
    }

    private function canvass($requisition, Vendor $winner)
    {
        $rfqs = app(RfqService::class);
        $rfq = $rfqs->open($requisition, now()->addDays(7));

        $others = [
            ['VEN-0004', 'Batangas Builders Supply', '130000.0000'],
            ['VEN-0005', 'Sierra Hardware & Cement', '131750.0000'],
        ];

        $rfqs->invite($rfq, $winner);

        $quotes = [[$winner, '124500.0000']];

        foreach ($others as [$code, $name, $price]) {
            $vendor = Vendor::updateOrCreate(
                ['code' => $code],
                ['organization_id' => $winner->organization_id, 'name' => $name],
            );

            app(VendorService::class)->accredit($vendor, now()->subMonth());
            $rfqs->invite($rfq, $vendor->fresh());
            $quotes[] = [$vendor->fresh(), $price];
        }

        $rfqs->issue($rfq);

        foreach ($quotes as [$vendor, $price]) {
            app(QuoteService::class)->record($rfq->fresh(), $vendor, [
                ['description' => 'Portland cement, Type 1P', 'quantity' => '500.0000', 'unit_price' => bcdiv($price, '500', 4)],
            ]);
        }

        return app(TabulationService::class)->tabulate($rfq->fresh());
    }

    private function order($tabulation, Vendor $vendor): PurchaseOrder
    {
        $orders = app(PurchaseOrderService::class);

        $order = $orders->raise($tabulation, [
            [
                'description' => 'Portland cement, Type 1P',
                'quantity' => '500.0000',
                'unit_price' => '249.0000',
                'unit' => 'bags',
            ],
        ]);

        PurchaseOrder::mutate(fn () => $order->update([
            'status' => PurchaseOrderStatus::Approved,
            // Relative to today, not a fixed date. A promised date months in the
            // past would make the scorecard read 118 days late, which looks like
            // broken data rather than a demonstration.
            'delivery_date' => now()->addDays(2)->toDateString(),
        ]));

        return $order->fresh();
    }

    /**
     * A short delivery, partly rejected — the two things the screens exist for.
     */
    private function deliver(PurchaseOrder $order, User $admin)
    {
        $report = app(ReceivingService::class)->receive(
            $order,
            'DR-88214',
            [[
                'purchase_order_line_id' => $order->lines()->sole()->getKey(),
                'quantity_received' => '480.0000',
                'remarks' => 'Twenty bags short — truck turned back at the weighbridge.',
            ]],
            $admin,
        );

        app(InspectionService::class)->inspect($report, [[
            'receiving_report_line_id' => $report->lines()->sole()->getKey(),
            'quantity_accepted' => '460.0000',
            'quantity_rejected' => '20.0000',
            'rejection_reason' => 'Hardened in transit — bags split and caked.',
        ]], $admin, 'Twenty bags set aside for return.');

        return $report->fresh();
    }

    private function store($report, CostCode $issueCode, User $admin): void
    {
        $stock = app(StockService::class);
        $card = $stock->receive($report, $admin);

        $issuance = $stock->issue(
            $card->fresh(),
            '200.0000',
            $issueCode->getKey(),
            $admin,
            'Pier cap pours, grid lines 4 to 9.',
            'Site — casting yard',
        );

        // The exit gate's last clause: material cost reaching the ledger.
        app(MaterialCostPoster::class)->post($issuance->fresh());
    }

    private function pay(PurchaseOrder $order, $report, User $admin): void
    {
        $payables = app(PayablesService::class);

        $payables->releaseAdvance($order, '25000.0000', 'Mobilisation advance, 20% against the award.', $admin);

        // 460 bags accepted at 249 is 114,540 — the invoice is for what was
        // accepted, not for what was ordered, and the match is what proves it.
        $match = app(ThreeWayMatchService::class)->match($order, $report, 'INV-2026-4471', '114540.0000', $admin);

        $payables->raise($match, 'goods', $admin);
    }

    private function equipment(Organization $organization, Project $project, CostCode $costCode, User $admin): void
    {
        $excavator = Equipment::updateOrCreate(
            ['code' => 'EQ-EXC-001'],
            [
                'organization_id' => $organization->getKey(),
                'description' => 'Hydraulic excavator, 20t',
                'category' => 'heavy_equipment',
                'serial_number' => 'ZX200-88431',
                'ownership' => Ownership::Owned,
                'acquisition_cost' => '4200000.0000',
                'salvage_value' => '700000.0000',
                'acquired_on' => '2026-01-15',
                'useful_life_months' => 60,
            ],
        );

        $equipment = app(EquipmentService::class);

        if ($equipment->openAssignmentFor($excavator->fresh()) === null) {
            $equipment->assign($excavator->fresh(), $project, now()->subDays(45), $admin);
        }

        $equipment->recordCost(
            $excavator->fresh(),
            EquipmentCostType::Fuel,
            '18500.0000',
            now()->subDays(10),
            $costCode,
            'FUEL-05-118',
            $admin,
        );

        if (! $excavator->fresh()->schedules()->exists()) {
            app(DepreciationService::class)->generate($excavator->fresh());
        }

        // A rented machine, so the register shows the distinction that decides
        // whether anything is depreciated at all.
        Equipment::updateOrCreate(
            ['code' => 'EQ-GEN-004'],
            [
                'organization_id' => $organization->getKey(),
                'description' => 'Generator set, 150kVA',
                'category' => 'plant',
                'ownership' => Ownership::Rented,
                'acquisition_cost' => '0.0000',
                'salvage_value' => '0.0000',
                'acquired_on' => null,
                'useful_life_months' => 0,
            ],
        );
    }

    /**
     * Mobilization, gated on the vendor countersigning the order — F2.
     */
    private function mobilize(Project $project, PurchaseOrder $order, User $admin): void
    {
        app(PermitService::class)->register(
            $project,
            PermitType::BuildingPermit,
            'BP-2026-0114',
            'Quezon City Office of the Building Official',
            now()->subMonths(4),
            now()->addMonths(8),
        );

        // The order was approved internally in the procurement half. This is the
        // vendor accepting it, and it is what opens the gate.
        app(PurchaseOrderService::class)->countersign(
            $order->fresh(),
            now()->subDays(40),
            'R. Villanueva, Northgate Cement Supply',
        );

        $mobilizations = app(MobilizationService::class);
        $mobilization = $mobilizations->mobilize($order->fresh(), now()->subDays(38));

        // Deliberately not every item. A completed checklist demonstrates
        // nothing; an outstanding one shows what the screen is for.
        foreach ([ChecklistItem::SiteOfficeEstablished, ChecklistItem::PermitsSecured, ChecklistItem::SafetyOfficerAssigned] as $item) {
            $mobilizations->completeItem($mobilization->fresh(), $item, $admin);
        }
    }

    /**
     * One full billing cycle, including a RETURNED billing.
     *
     * The returned one is the point. PHASE-PLAN.md calls that branch a
     * first-class path, and a demo where every billing sails through shows none
     * of what Phase 2 actually built — the deduction, its reason, and the block
     * that stops the same line being billed again next month.
     */
    private function bill(Project $project, Contract $contract, User $admin): void
    {
        $schedules = app(BillingScheduleService::class);
        $billings = app(BillingService::class);
        $collections = app(CollectionService::class);

        $schedule = $schedules->createFor($contract);

        // --- 30% downpayment: submitted, approved, invoiced, part collected ---
        $downpayment = $schedule->milestones()->where('code', 'downpayment_30')->sole();

        foreach (['billing_form', 'signed_contract', 'invoice_request'] as $key) {
            $schedules->attachDocument($downpayment->fresh(), $key, strtoupper(str_replace('_', '-', $key)).'-2026-01', $admin);
        }

        $first = $billings->submit($downpayment->fresh(), [
            [
                'description' => '30% downpayment per contract CON-2026-014',
                'amount' => $schedules->amountFor($downpayment->fresh()),
                'line_key' => 'downpayment',
            ],
        ], $admin);

        $billings->approve($first, now()->subDays(35), 'Approved in full.');

        $invoice = $collections->invoice($first->fresh(), now()->subDays(34), 'services', $admin);

        app(RetentionService::class)->withhold($invoice->fresh(), $admin);
        app(RevenuePoster::class)->post($invoice->fresh());

        $collections->collect(
            $invoice->fresh(),
            '8000000.0000',
            now()->subDays(20),
            'BDO transfer 9911-2',
            '2307-2026-0451',
            $admin,
        );

        // --- 50% progress: measured, surveyed, RETURNED with a deduction ---
        $accomplishments = app(AccomplishmentService::class);

        $measured = $accomplishments->record(
            $project,
            now()->subDays(45)->startOfMonth(),
            now()->subDays(45)->endOfMonth(),
            '52.00',
            $admin,
        );

        $survey = $accomplishments->openSurvey($measured, now()->subDays(30), 'E. Cruz', 'M. Reyes');
        $accomplishments->signAsContractor($survey, $admin);
        $accomplishments->signAsClient($survey->fresh(), 'E. Cruz');

        $progress = $schedule->milestones()->where('code', 'progress_50')->sole();

        foreach (['accomplishment_report', 'joint_survey', 'photos'] as $key) {
            $schedules->attachDocument($progress->fresh(), $key, strtoupper(str_replace('_', '-', $key)).'-2026-05', $admin);
        }

        $second = $billings->submit($progress->fresh(), [
            ['description' => 'Deck slab pour, grid 4-9', 'amount' => '9300000.0000', 'line_key' => 'deck-slab'],
            ['description' => 'Pier 7 rebar', 'amount' => '400000.0000', 'line_key' => 'pier-7-rebar'],
        ], $admin);

        $billings->returnForRemeasurement(
            $second,
            now()->subDays(12),
            'Pier 7 rebar not tied at the date of the joint survey. Re-measure and resubmit next cutoff.',
            [['line_key' => 'pier-7-rebar', 'reason' => 'Not tied at survey date.']],
        );

        // The AR sweep, run as the weekly review would run it. The downpayment
        // invoice is 34 days old and part paid, so it escalates.
        app(ArAgingService::class)->sweep(now(), $project);
    }

    /**
     * One semi-monthly payroll cutoff, run through the real services.
     *
     * Deliberately untidy, like the procurement and billing halves. Two workers,
     * and one of them has **a day held**: worked, not certified in time, and
     * carried into the next cutoff. A demo where every day is certified shows
     * none of what P3-05 was built for.
     *
     * Overtime is authorised for two hours on a day somebody worked four past
     * eight, so the "Payable" column on the authorities screen shows the cap
     * doing its job rather than a number that matches what was asked for.
     */
    private function payroll(Organization $organization, Project $project, CostCode $costCode, User $admin): void
    {
        $employees = app(EmployeeService::class);
        $hiring = app(HiringService::class);
        $timekeeping = app(TimekeepingService::class);

        $cutoffStart = now()->copy()->subMonth()->startOfMonth();
        $cutoffEnd = $cutoffStart->copy()->addDays(14);

        $crew = [];

        foreach ([
            ['EMP-0001', 'Marisol', 'Reyes', 'Carpenter', '1200.0000'],
            ['EMP-0002', 'Danilo', 'Bautista', 'Mason', '1350.0000'],
        ] as [$number, $first, $last, $position, $rate]) {
            $employee = Employee::query()->firstWhere('employee_number', $number)
                ?? $employees->hire($organization, [
                    'employee_number' => $number,
                    'first_name' => $first,
                    'last_name' => $last,
                    'position' => $position,
                    'date_hired' => $cutoffStart->copy()->subMonths(3)->toDateString(),
                    // Encrypted at rest by the model's casts.
                    'sss_number' => '34-5678901-2',
                    'philhealth_number' => '12-345678901-2',
                    'pagibig_number' => '1234-5678-9012',
                    'tin' => '123-456-789-000',
                    'bank_account_number' => '00'.substr($number, -8),
                ]);

            if ($employee->employmentContracts()->doesntExist()) {
                $contract = $hiring->issueContract(
                    $employee,
                    $cutoffStart->copy()->subMonths(3),
                    null,
                    PayBasis::Daily,
                    $rate,
                );

                // Signed BEFORE the first shift — the gate every timelog checks.
                $hiring->signContract($contract, $cutoffStart->copy()->subMonths(3)->subDay(), $first.' '.$last, $admin);
            }

            $crew[] = $employee->fresh();
        }

        // Ten working days across the cutoff, plus one long day for the overtime.
        $days = [];
        $cursor = $cutoffStart->copy();

        while (count($days) < 10 && $cursor->lte($cutoffEnd)) {
            if (! $cursor->isWeekend()) {
                $days[] = $cursor->toDateString();
            }

            $cursor->addDay();
        }

        foreach ($crew as $index => $employee) {
            $rows = [];

            foreach ($days as $position => $date) {
                $rows[] = [
                    'employee_number' => $employee->employee_number,
                    'work_date' => $date,
                    'time_in' => '07:00',
                    // The first worker's third day runs to 20:00 — four hours past
                    // the standard day, of which only two are authorised.
                    'time_out' => ($index === 0 && $position === 2) ? '20:00' : '16:00',
                    'break_minutes' => 60,
                ];
            }

            $result = $timekeeping->import($project, $rows, 'DEMO-BIOMETRICS');

            if ($result['rejected'] !== []) {
                throw new \RuntimeException('Demo timelog import rejected rows: '.json_encode($result['rejected']));
            }
        }

        // The overtime authority, approved in writing by somebody other than the
        // requester.
        $overtime = app(OvertimeService::class);
        $authority = $overtime->request(
            $crew[0],
            $project,
            Carbon::parse($days[2]),
            OvertimeType::Overtime,
            '2.00',
            'Deck pour ran past sunset.',
            $this->approver('project-manager'),
        );
        $overtime->approve($authority, $admin, 'SE memo '.$days[2].'-02');

        // The site certifies everything except ONE day for the second worker.
        $heldDate = $days[4];

        foreach ($crew as $index => $employee) {
            foreach ($days as $date) {
                if ($index === 1 && $date === $heldDate) {
                    continue;
                }

                $record = DailyTimeRecord::query()
                    ->where('employee_id', $employee->getKey())
                    ->whereDate('work_date', $date)
                    ->sole();

                $timekeeping->validate($record, $admin);
            }
        }

        $payroll = app(PayrollService::class);
        $run = $payroll->compute($payroll->open($organization, $cutoffStart, $cutoffEnd, $admin), $admin);

        // First register for this organization, so F10 has no prior cutoff to
        // vary against and nothing to explain.
        if (app(PayrollVarianceService::class)->unexplained($run) === []) {
            $payroll->approve($run->fresh(), $admin);
        }

        $run = $run->fresh();

        // Labour cost into the ledger, on the payroll calendar.
        app(LaborCostPoster::class)->post($run, $costCode);

        // Bank upload, transmitted — the half that needs no signature.
        $batch = app(DisbursementService::class)->prepare($run->fresh(), DisbursementMethod::BankUpload, $admin);
        app(DisbursementService::class)->markTransmitted($batch, 'BDO-BATCH-77341', $admin);
    }

    /**
     * One OPEX month, run through the real services and left AT THE BUDGET
     * REVIEW — blocked, deliberately.
     *
     * The demo's whole value in the other two chains was showing the untidy
     * case, and here the untidy case is the gate holding: an expense returned to
     * site and barred from its period, a cash advance swept to payroll at the
     * day-26 cutoff, and a variance above the threshold that nobody has
     * explained yet. A month that closed cleanly would demonstrate none of what
     * Phase 4 built.
     */
    private function opex(Organization $organization, Project $project, CostCode $costCode, User $admin): void
    {
        $expenses = app(ExpenseService::class);
        $advances = app(CashAdvanceService::class);
        $calendar = app(OpexCalendarService::class);

        $month = now()->copy()->subMonth()->startOfMonth();
        $year = (int) $month->year;
        $monthNumber = (int) $month->month;

        // A cost code for site utilities, budgeted so expenses can be captured
        // against it at all.
        $utilities = CostCode::updateOrCreate(
            ['organization_id' => $organization->getKey(), 'code' => '08.10.100'],
            ['name' => 'Site utilities and permits'],
        );

        $budget = $project->budgets()->firstOrFail();

        $budget->lines()->updateOrCreate(
            ['cost_code_id' => $utilities->getKey()],
            ['amount' => '100000.0000'],
        );

        // Captured, coded, and inside the budget.
        $expenses->capture($project, $utilities, '44500.0000', $month->copy()->addDays(6), 'Meralco, site office', 'OR-MER-'.$year.$monthNumber, $admin);
        $expenses->capture($project, $utilities, '18200.0000', $month->copy()->addDays(9), 'Maynilad, site office', 'OR-MAY-'.$year.$monthNumber, $admin);

        // One RETURNED to the site — and therefore barred from this period.
        $returned = $expenses->capture($project, $utilities, '61000.0000', $month->copy()->addDays(11), 'Generator hire', 'OR-GEN-'.$year.$monthNumber, $admin);
        $expenses->returnToSite($returned, 'Receipt is for the Batangas site, not this one.', $admin);

        // A cash advance, part liquidated — the rest will be swept at cutoff.
        $holder = Employee::query()->where('employee_number', 'EMP-0001')->first();

        if ($holder !== null) {
            $advance = $advances->release($holder, $project, '10000.0000', $month->copy()->addDays(3), 'Site petty cash.', $admin);
            $advances->liquidateWithExpense($advance, $utilities, '8400.0000', $month->copy()->addDays(8), 'Consumables and fuel', 'OR-PC-'.$year.$monthNumber, $admin);
        }

        // Walk the calendar to the budget review. The cutoff transition is what
        // sweeps the unliquidated 1,600 into a payroll deduction.
        $period = $calendar->open($organization, $year, $monthNumber);

        foreach ([OpexStage::Cutoff, OpexStage::Coding, OpexStage::Validation, OpexStage::Consolidation, OpexStage::BudgetReview] as $stage) {
            $period = $calendar->advanceTo($period->fresh(), $stage, $admin);
        }

        // Overhead into the ledger — the fourth chain to arrive there.
        app(OverheadPoster::class)->postPeriod($project, $year, $monthNumber);

        /*
         * The month is left AT BUDGET REVIEW on purpose. Captured expenses total
         * 71,100 against a 100,000 line — a 29% underspend, above the 10%
         * threshold — so the close is blocked until somebody explains it, and the
         * calendar screen shows exactly that.
         */
    }

    /**
     * A user holding the role a matrix step asks for.
     *
     * Created rather than assumed: the authority matrix names roles, and a
     * seeded chain that could not find one would silently stop halfway.
     */
    private function approver(string $role): User
    {
        $user = User::firstOrCreate(
            ['email' => str_replace('-', '.', $role).'@construction.test'],
            [
                'name' => ucwords(str_replace('-', ' ', $role)),
                // From the guard, not a literal. These accounts hold real approval
                // authority, and outside local and testing a default password on
                // them is the same backdoor as a default admin (P6-08).
                'password' => bcrypt(app(DemoSeedGuard::class)->adminPassword(
                    (string) app()->environment(),
                    config('ops.demo_admin_password'),
                )),
            ],
        );

        // Created rather than assumed: the matrix names roles by string, and a
        // seeded chain that could not find one would stop halfway with no sign
        // of where.
        Role::findOrCreate($role);

        $user->assignRole($role);

        return $user->fresh();
    }
}
