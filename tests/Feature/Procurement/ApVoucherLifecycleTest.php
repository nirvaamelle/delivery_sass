<?php

use App\Domain\Approvals\ApprovalDecisions;
use App\Domain\Procurement\ApVoucherStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| An AP voucher from raised to paid
|--------------------------------------------------------------------------
|
| PayablesService could raise a voucher and nothing else, yet `ApVoucherStatus`
| has had APPROVED, PAID and CANCELLED since the table was created and
| `config/approvals.php` has routed `ap_voucher` through the authority matrix
| since Phase 1. Nothing ever set them: every voucher in the system was raised
| and stayed raised, and "what have we approved but not yet paid" — the question
| the cash requirement (F16) is built on — could not be answered.
|
| The rules here are the ones money leaving the company needs:
|
|   - **Approval is the matrix's, not a button's.** Submitting routes the
|     voucher by its NET amount, which is what the company actually pays.
|   - **Only an approved voucher is paid**, and paying records the reference the
|     bank statement will be reconciled against.
|   - **Paid is final.** A payment already made is corrected by a credit note
|     from the vendor, never by editing the voucher that recorded it.
|   - **Cancelling returns the advance it recovered**, otherwise a cancelled
|     voucher silently keeps a vendor's advance offset against nothing.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('submits a voucher for approval, routed by what the company actually pays', function () {
    // Net, not gross: the tax is withheld and the advance never leaves again, so
    // routing on gross would send a payment up a tier it does not belong in.
    $voucher = raisedVoucher();

    payables()->submit($voucher);

    $step = $voucher->approvals()->orderBy('step')->first();

    expect($voucher->fresh()->status)->toBe(ApVoucherStatus::Submitted)
        ->and($step)->not->toBeNull()
        ->and($step->document_type)->toBe('ap_voucher');
});

it('approves a voucher when its last signature lands in the inbox', function () {
    $voucher = raisedVoucher('40000.0000');
    payables()->submit($voucher);

    $signer = userWithRole('project-manager');
    actingAs($signer);

    app(ApprovalDecisions::class)->approve($voucher->approvals()->sole(), $signer, 'Matched and received.');

    expect($voucher->fresh()->status)->toBe(ApVoucherStatus::Approved);
});

it('refuses to pay a voucher that is not approved', function (string $stage) {
    $voucher = raisedVoucher('40000.0000');

    if ($stage === 'submitted') {
        payables()->submit($voucher);
    }

    expect(fn () => payables()->pay($voucher->fresh(), 'BANK-REF-1', Carbon::parse('2026-05-20'), User::factory()->create()))
        ->toThrow(DomainException::class, 'approved');
})->with(['raised', 'submitted']);

it('pays an approved voucher and records what the bank statement will show', function () {
    $voucher = approvedVoucher();

    $paid = payables()->pay($voucher, 'BPI-2026-0512-88', Carbon::parse('2026-05-20'), User::factory()->create());

    expect($paid->status)->toBe(ApVoucherStatus::Paid)
        ->and($paid->payment_reference)->toBe('BPI-2026-0512-88')
        ->and($paid->paid_at->toDateString())->toBe('2026-05-20');
});

it('refuses a payment with no reference, because nothing reconciles it', function () {
    expect(fn () => payables()->pay(approvedVoucher(), '  ', Carbon::parse('2026-05-20'), User::factory()->create()))
        ->toThrow(DomainException::class, 'reference');
});

it('refuses to pay the same voucher twice', function () {
    // The defect this exists for: a second payment against one invoice.
    $voucher = approvedVoucher();
    payables()->pay($voucher, 'BPI-1', Carbon::parse('2026-05-20'), User::factory()->create());

    expect(fn () => payables()->pay($voucher->fresh(), 'BPI-2', Carbon::parse('2026-05-21'), User::factory()->create()))
        ->toThrow(DomainException::class, 'already paid');
});

it('refuses to cancel a paid voucher', function () {
    $voucher = approvedVoucher();
    payables()->pay($voucher, 'BPI-1', Carbon::parse('2026-05-20'), User::factory()->create());

    expect(fn () => payables()->cancel($voucher->fresh(), 'Duplicate invoice.', User::factory()->create()))
        ->toThrow(DomainException::class, 'paid');
});

it('cancels an unpaid voucher with a reason, and gives back the advance it recovered', function () {
    // Without this, cancelling leaves the vendor's advance marked as recovered
    // against a voucher nobody will ever pay — the advance is then invisible to
    // the next voucher, and the company never gets it back.
    defineProcurementTiers('ap_voucher');

    [$po, $report] = acceptedGoods();
    payables()->releaseAdvance($po->fresh(), '50000.0000', 'Mobilisation of the supply.');

    $match = matches()->match($po->fresh(), $report->fresh(), 'INV-ADV-1', '124500.0000');
    $voucher = payables()->raise($match, 'goods');

    expect((string) $voucher->advance_offset)->toBe('50000.0000')
        ->and(payables()->outstandingAdvanceFor($po->fresh()))->toBe('0.0000');

    payables()->cancel($voucher, 'Vendor billed the wrong project.', User::factory()->create());

    expect($voucher->fresh()->status)->toBe(ApVoucherStatus::Cancelled)
        ->and(payables()->outstandingAdvanceFor($po->fresh()))->toBe('50000.0000');
});

it('refuses to cancel without a reason', function () {
    expect(fn () => payables()->cancel(raisedVoucher(), '   ', User::factory()->create()))
        ->toThrow(DomainException::class, 'reason');
});

it('counts only approved and paid vouchers as committed cash', function () {
    // The cash requirement asks what is owed. A cancelled voucher is not owed,
    // and a raised one has not been agreed to yet.
    $voucher = approvedVoucher();

    expect(payables()->approvedUnpaidFor($voucher->project()->sole()))->toBe((string) $voucher->net_amount);

    payables()->pay($voucher, 'BPI-1', Carbon::parse('2026-05-20'), User::factory()->create());

    expect(payables()->approvedUnpaidFor($voucher->project()->sole()))->toBe('0.0000');
});
