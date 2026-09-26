<?php

namespace App\Domain\Approvals;

use App\Domain\Procurement\PayablesService;
use App\Domain\Procurement\PurchaseOrderService;
use App\Domain\Procurement\SoleSourceService;
use App\Domain\Requisitions\RequisitionService;
use App\Models\Approval;
use App\Models\ApVoucher;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\SoleSourceJustification;
use App\Models\User;

/**
 * A decision taken in the approvals inbox, carried to the document it decides.
 *
 * **Why this exists.** ApprovalRouter records a signature on an approval step
 * and nothing else. What the signature MEANS for the document — a purchase
 * order becoming approved once its last step is signed, a requisition becoming
 * returned and releasing the budget it claimed — lives in each document's own
 * service. The inbox called the router directly, so a document approved on
 * screen stayed "submitted" for ever, and the chain behind it refused to start.
 *
 * Each service still calls the router itself, inside its own transaction, so the
 * authority check (the signer must hold the step's role) runs exactly once and
 * the signature and the status change commit together or not at all.
 *
 * A document type with no service of its own falls back to the router, which
 * records the signature — what the inbox did before, for every type.
 */
class ApprovalDecisions
{
    public function __construct(
        private readonly ApprovalRouter $router,
        private readonly RequisitionService $requisitions,
        private readonly PurchaseOrderService $orders,
        private readonly SoleSourceService $soleSources,
        private readonly PayablesService $payables,
    ) {}

    /**
     * @throws ApproverLacksAuthorityException
     */
    public function approve(Approval $step, User $approver, ?string $remarks = null): Approval
    {
        $document = $step->approvable;

        match (true) {
            $document instanceof PurchaseRequisition => $this->requisitions->approve($document, $step, $approver, $remarks),
            $document instanceof PurchaseOrder => $this->orders->approve($document, $step, $approver, $remarks),
            $document instanceof SoleSourceJustification => $this->soleSources->approve($document, $step, $approver, $remarks),
            $document instanceof ApVoucher => $this->payables->approve($document, $step, $approver, $remarks),
            default => $this->router->approve($step, $approver, $remarks),
        };

        return $step->refresh();
    }

    /**
     * @throws ApproverLacksAuthorityException
     */
    public function returnForRevision(Approval $step, User $approver, string $reason): Approval
    {
        $document = $step->approvable;

        match (true) {
            $document instanceof PurchaseRequisition => $this->requisitions->returnForRevision($document, $step, $approver, $reason),
            $document instanceof PurchaseOrder => $this->orders->returnForRevision($document, $step, $approver, $reason),
            $document instanceof SoleSourceJustification => $this->soleSources->returnForRevision($document, $step, $approver, $reason),
            $document instanceof ApVoucher => $this->payables->returnForRevision($document, $step, $approver, $reason),
            default => $this->router->returnForRevision($step, $approver, $reason),
        };

        return $step->refresh();
    }
}
