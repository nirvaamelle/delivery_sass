<?php

namespace App\Domain\Requisitions;

use App\Domain\Approvals\ApprovalDecision;
use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Gates\GateFailedException;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Projects\ProjectTransition;
use App\Models\Approval;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use App\Models\PurchaseRequisitionLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Raising and submitting a purchase requisition.
 *
 * This is the first document that puts the Phase 0 substrate to work together:
 * the project gate (F14) decides whether the project may spend at all, the
 * Numbering service issues the reference, the budget check enforces PLAN.md
 * §5's first control, and the Approvals service routes it by amount.
 *
 * The chain around it — RFQ, canvass, tabulation, PO — is Phase 1. What is here
 * is only what the Phase 0 exit gate is worded around.
 */
class RequisitionService
{
    private const SCALE = 4;

    public function __construct(
        private readonly Gatekeeper $gates,
        private readonly DocumentNumberGenerator $numbering,
        private readonly ApprovalRouter $approvals,
    ) {}

    /**
     * Raise a draft requisition against a project.
     *
     * The project gate runs first and unconditionally. F14: no requisition
     * against a project without a signed contract and an opened budget — which
     * is checked here rather than at submission, because there is no point
     * letting someone fill in twenty lines against a project that was never
     * able to spend.
     *
     * @param  array<int, array{cost_code_id: int, description: string, amount: string}>  $lines
     *
     * @throws GateFailedException when the project may not spend
     */
    public function raise(Project $project, array $lines): PurchaseRequisition
    {
        $this->gates->assert($project, ProjectTransition::RaisePurchaseRequisition->value);

        return DB::transaction(function () use ($project, $lines): PurchaseRequisition {
            // Inside the transaction, so a failure here takes the document
            // number back with it rather than leaving a hole in the sequence.
            $requisition = PurchaseRequisition::query()->create([
                'project_id' => $project->getKey(),
                'number' => $this->numbering->next('PR'),
                'status' => RequisitionStatus::Draft,
                'raised_by_user_id' => auth()->id(),
                'total_amount' => '0.0000',
            ]);

            $total = '0';

            foreach ($lines as $line) {
                $requisition->lines()->create([
                    'cost_code_id' => $line['cost_code_id'],
                    'description' => $line['description'],
                    'amount' => $line['amount'],
                ]);

                $total = bcadd($total, $line['amount'], self::SCALE);
            }

            $requisition->update(['total_amount' => bcadd($total, '0', self::SCALE)]);

            return $requisition->refresh();
        });
    }

    /**
     * Submit a requisition for approval.
     *
     * The budget check happens here and not at raise time, because the question
     * "is there budget for this" is only meaningful once the lines exist and
     * only binding at the moment of asking — availability moves as other
     * requisitions are submitted.
     *
     * @throws BudgetExceededException when any cost code is over its remaining budget
     */
    public function submit(PurchaseRequisition $requisition): PurchaseRequisition
    {
        $project = $requisition->project()->sole();

        return DB::transaction(function () use ($requisition, $project): PurchaseRequisition {
            $this->assertWithinBudget($requisition, $project);

            $requisition->update([
                'status' => RequisitionStatus::Submitted,
                'submitted_at' => now(),
            ]);

            // Routed by amount through the authority matrix. The tier decides
            // how many signatures it needs; the requisition does not choose.
            $this->approvals->request(
                $requisition,
                'purchase_requisition',
                (string) $requisition->total_amount,
            );

            return $requisition->refresh();
        });
    }

    /**
     * Record one approver's signature.
     *
     * The requisition only becomes approved when EVERY step of its tier has
     * signed. A tier that requires two signatures and acts on one is not a
     * two-tier approval, and the authority matrix would mean nothing.
     */
    public function approve(
        PurchaseRequisition $requisition,
        Approval $step,
        User $approver,
        ?string $remarks = null,
    ): PurchaseRequisition {
        return DB::transaction(function () use ($requisition, $step, $approver, $remarks): PurchaseRequisition {
            $this->approvals->approve($step, $approver, $remarks);

            $outstanding = $requisition->approvals()
                ->where('decision', ApprovalDecision::Pending)
                ->exists();

            if (! $outstanding) {
                $requisition->update(['status' => RequisitionStatus::Approved]);
            }

            return $requisition->refresh();
        });
    }

    /**
     * Send the requisition back for revision.
     *
     * One return is enough - the document goes back whole rather than
     * collecting the remaining signatures on something already known to be
     * wrong.
     *
     * Returning also frees the budget the requisition had claimed, because
     * RequisitionStatus::Returned does not commit. Without that, a rejected
     * document would block the corrected one meant to replace it.
     */
    public function returnForRevision(
        PurchaseRequisition $requisition,
        Approval $step,
        User $approver,
        string $reason,
    ): PurchaseRequisition {
        return DB::transaction(function () use ($requisition, $step, $approver, $reason): PurchaseRequisition {
            $this->approvals->returnForRevision($step, $approver, $reason);

            $requisition->update(['status' => RequisitionStatus::Returned]);

            return $requisition->refresh();
        });
    }

    /**
     * What is left to spend against a cost code on a project.
     *
     * The budgeted amount minus everything already committed. Availability is
     * not the budget line: if it were, every requisition would see the full
     * budget and the tenth would be approved as readily as the first.
     */
    public function availableFor(Project $project, CostCode $costCode): string
    {
        return bcsub(
            $this->budgetedFor($project, $costCode),
            $this->committedFor($project, $costCode),
            self::SCALE
        );
    }

    /**
     * The budgeted amount for a cost code, across the project's open budgets.
     *
     * A cost code with no budget line has zero, not unlimited. Reading "no
     * budget line" as "no limit" is how overspend enters a project unnoticed.
     */
    public function budgetedFor(Project $project, CostCode $costCode): string
    {
        $amounts = $project->budgets()
            ->where('status', BudgetStatus::Open)
            ->with('lines')
            ->get()
            ->flatMap(fn ($budget) => $budget->lines)
            ->where('cost_code_id', $costCode->getKey())
            ->pluck('amount');

        return $this->sum($amounts->all());
    }

    /**
     * What existing requisitions have already laid claim to.
     */
    public function committedFor(Project $project, CostCode $costCode): string
    {
        $committed = array_map(
            fn (RequisitionStatus $status): string => $status->value,
            array_filter(
                RequisitionStatus::cases(),
                fn (RequisitionStatus $status): bool => $status->commitsBudget(),
            ),
        );

        $amounts = PurchaseRequisitionLine::query()
            ->where('cost_code_id', $costCode->getKey())
            ->whereHas(
                'requisition',
                fn ($query) => $query
                    ->where('project_id', $project->getKey())
                    ->whereIn('status', $committed),
            )
            ->pluck('amount');

        return $this->sum($amounts->all());
    }

    /**
     * @throws BudgetExceededException
     */
    private function assertWithinBudget(PurchaseRequisition $requisition, Project $project): void
    {
        // Summed per cost code before comparing. Checking line by line would
        // pass a requisition whose lines each fit but which together do not.
        $requested = [];

        foreach ($requisition->lines()->get() as $line) {
            $key = (int) $line->cost_code_id;
            $requested[$key] = bcadd($requested[$key] ?? '0', (string) $line->amount, self::SCALE);
        }

        foreach ($requested as $costCodeId => $amount) {
            $costCode = CostCode::query()->findOrFail($costCodeId);
            $available = $this->availableFor($project, $costCode);

            if (bccomp($amount, $available, self::SCALE) > 0) {
                throw new BudgetExceededException(sprintf(
                    'Cost code %s has %s available; this requisition asks for %s.',
                    $costCode->code,
                    $available,
                    $amount
                ));
            }
        }
    }

    /**
     * @param  array<int, mixed>  $amounts
     */
    private function sum(array $amounts): string
    {
        $total = '0';

        foreach ($amounts as $amount) {
            $total = bcadd($total, (string) $amount, self::SCALE);
        }

        return bcadd($total, '0', self::SCALE);
    }
}
