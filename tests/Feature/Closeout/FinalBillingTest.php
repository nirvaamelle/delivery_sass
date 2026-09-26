<?php

use App\Domain\Closeout\FinalDeductionType;
use App\Models\FinalBillingDeduction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Final billing — P5-05
|--------------------------------------------------------------------------
|
| Slide 9 step 4, and the clause the task is named after: "Step 4 applies
| deductions and back-charges BEFORE the invoice." With the structural reading
| PHASE-PLAN.md draws from it: "final billing depends on back-charges being
| computed first, which makes step 2 a hard predecessor of step 4 across two
| different chains."
|
| **Before the invoice is not a sequencing preference, it is the whole control.**
| An invoice raised at the gross and corrected afterwards has already been sent.
| The client has a document saying one number, the ledger says another, and the
| difference is chased by whoever notices first. So the deductions are applied to
| the billing, the invoice is computed FROM the deducted net, and a back-charge
| that is not represented in the deductions stops the invoice rather than
| following it.
|
| **The predecessor is enforced, not documented.** A cleared subcontractor defect
| nobody has priced is an open question about how much the client owes, so the
| final billing refuses to be raised over it — and names the item, because
| "back-charges outstanding" is not something a QS can act on.
|
| Back-charge deductions are DERIVED. They cannot be typed: the amount is
| whatever P5-02 computed at punchlist clearing, and a hand-entered one is a
| second opinion about a number that already exists.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
    billingCalendarForMay();
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Raising the final billing
|--------------------------------------------------------------------------
*/

it('raises the final billing with every back-charge applied as a deduction', function () {
    $f = finalBillable();

    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());

    expect(finalBillings()->deductionsFor($billing)->pluck('back_charge_id')->all())
        ->toBe([$f['backCharge']->getKey()])
        ->and(finalBillings()->totalDeductions($billing))->toBe('48250.0000');
});

it('nets the billing at gross less retention less deductions', function () {
    // 2,425,000 gross · 242,500 retention at 10% · 48,250 back-charged.
    $f = finalBillable();

    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());

    expectMoney($billing->net_amount);
    expect($billing->gross_amount)->toBe('2425000.0000')
        ->and($billing->retention_amount)->toBe('242500.0000')
        ->and($billing->deductions_amount)->toBe('48250.0000')
        ->and($billing->net_amount)->toBe('2134250.0000');
});

it('refuses to raise a final billing on a milestone that is not the final one', function () {
    [, $milestone] = billableProgress();

    expect(fn () => finalBillings()->raise($milestone, finalLines(), User::factory()->create()))
        ->toThrow(DomainException::class, 'not the final milestone');
});

it('refuses a final billing on a project with no turnover pack', function () {
    $f = finalBillable(turnover: false);

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create()))
        ->toThrow(DomainException::class, 'no turnover pack');
});

it('refuses a final billing while the client has not accepted the turnover', function () {
    // Slide 9's order again. Billing for the works in full before the client has
    // accepted them invites the whole invoice to be disputed.
    $f = finalBillable(accept: false);

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create()))
        ->toThrow(DomainException::class, 'has not been accepted');
});

it('refuses a final billing while a cleared subcontractor defect is unpriced', function () {
    // The hard predecessor, as a refusal. What the client owes cannot be
    // computed while what the subcontractor owes is an open question.
    $f = finalBillable(priced: false);

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create()))
        ->toThrow(DomainException::class, 'not been priced');
});

it('names the unpriced item rather than reporting a count', function () {
    $f = finalBillable(priced: false);

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create()))
        ->toThrow(DomainException::class, 'Steel handrail out of plumb');
});

/*
|--------------------------------------------------------------------------
| Deductions that are not back-charges
|--------------------------------------------------------------------------
*/

it('records a manual deduction alongside the derived back-charges', function () {
    $f = finalBillable();

    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create(), [
        [
            'type' => FinalDeductionType::LiquidatedDamages,
            'description' => '14 days late at 1/10 of 1% of the contract sum per day',
            'amount' => '67900.0000',
        ],
    ]);

    expect(finalBillings()->totalDeductions($billing))->toBe('116150.0000')
        ->and($billing->net_amount)->toBe('2066350.0000');
});

it('refuses a back-charge typed in by hand', function () {
    // The amount was computed at punchlist clearing. A hand-entered one is a
    // second opinion about a number that already exists, and the two will differ.
    $f = finalBillable();

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create(), [
        ['type' => FinalDeductionType::BackCharge, 'description' => 'Handrail', 'amount' => '48250.0000'],
    ]))->toThrow(DomainException::class, 'computed at punchlist clearing');
});

it('refuses a deduction that is not positive', function () {
    $f = finalBillable();

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create(), [
        ['type' => FinalDeductionType::Other, 'description' => 'Adjustment', 'amount' => '-500.0000'],
    ]))->toThrow(DomainException::class, 'positive');
});

it('refuses a deduction with nothing said about what it is for', function () {
    $f = finalBillable();

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create(), [
        ['type' => FinalDeductionType::ClientDeduction, 'description' => '  ', 'amount' => '500.0000'],
    ]))->toThrow(DomainException::class, 'description');
});

it('refuses a final billing whose deductions exceed what is being billed', function () {
    // The client owes nothing and we owe them. That is a settlement, and this
    // build has no credit note — an invoice for a negative amount would go
    // straight into the AR sweep as something to chase.
    $f = finalBillable();

    expect(fn () => finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create(), [
        ['type' => FinalDeductionType::LiquidatedDamages, 'description' => 'Delay', 'amount' => '2500000.0000'],
    ]))->toThrow(DomainException::class, 'settlement');
});

it('refuses a deduction that is not positive, at the database', function () {
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());

    expect(fn () => FinalBillingDeduction::query()
        ->whereKey(finalBillings()->deductionsFor($billing)->first()->getKey())
        ->update(['amount' => '0.0000']))->toThrow(QueryException::class);
});

it('refuses a back-charge deduction pointing at no back-charge, at the database', function () {
    // The typed distinction, guarded past the service: a row that says
    // "back_charge" and names none is a hand-entered one wearing the label.
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());

    expect(fn () => FinalBillingDeduction::query()
        ->whereKey(finalBillings()->deductionsFor($billing)->first()->getKey())
        ->update(['back_charge_id' => null]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| The invoice reads the deducted number
|--------------------------------------------------------------------------
*/

it('invoices the final billing at gross less retention less deductions', function () {
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());
    billings()->approve($billing, Carbon::parse('2026-05-21'));

    $invoice = collections()->invoice($billing->fresh(), Carbon::parse('2026-05-22'));

    expect($invoice->collectible_amount)->toBe('2134250.0000');
});

it('refuses to invoice a final billing with a back-charge missing from its deductions', function () {
    // The gate, tested the way the build tests every control it does not want
    // resting on one service method: the row is removed at the database, which
    // is what an importer or a console command leaves behind.
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());
    billings()->approve($billing, Carbon::parse('2026-05-21'));
    FinalBillingDeduction::query()->whereKey(finalBillings()->deductionsFor($billing)->first()->getKey())->delete();

    expect(fn () => collections()->invoice($billing->fresh(), Carbon::parse('2026-05-22')))
        ->toThrow(Exception::class, $f['backCharge']->number);
});

it('leaves an ordinary progress billing invoicing exactly as it did', function () {
    // A regression test with a purpose: the deduction column defaults to zero,
    // and every billing raised before this task has to keep its number.
    $billing = approvedBilling();

    $invoice = collections()->invoice($billing, Carbon::parse('2026-05-22'));

    expect($invoice->collectible_amount)->toBe('13095000.0000');
});

/*
|--------------------------------------------------------------------------
| A deduction found late
|--------------------------------------------------------------------------
*/

it('applies a deduction found after the billing was raised', function () {
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());

    $reworked = finalBillings()->applyDeductions($billing, [
        ['type' => FinalDeductionType::ClientDeduction, 'description' => 'Client-supplied materials', 'amount' => '12000.0000'],
    ]);

    expect($reworked->deductions_amount)->toBe('60250.0000')
        ->and($reworked->net_amount)->toBe('2122250.0000');
});

it('refuses to apply a deduction once the invoice has been raised', function () {
    // "Before the invoice" read strictly. Afterwards the client holds a document
    // saying a number, and changing the billing underneath it does not change
    // theirs — a correction is a credit note, which this build does not have.
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create());
    billings()->approve($billing, Carbon::parse('2026-05-21'));
    collections()->invoice($billing->fresh(), Carbon::parse('2026-05-22'));

    expect(fn () => finalBillings()->applyDeductions($billing->fresh(), [
        ['type' => FinalDeductionType::Other, 'description' => 'Late adjustment', 'amount' => '1000.0000'],
    ]))->toThrow(DomainException::class, 'already been invoiced');
});

it('states the deductions as a statement, each with what it is and where it came from', function () {
    $f = finalBillable();
    $billing = finalBillings()->raise($f['milestone'], finalLines(), User::factory()->create(), [
        ['type' => FinalDeductionType::LiquidatedDamages, 'description' => '14 days late', 'amount' => '67900.0000'],
    ]);

    $statement = finalBillings()->statement($billing);

    expect($statement)->toHaveCount(2)
        ->and($statement[0]['type'])->toBe('back_charge')
        ->and($statement[0]['reference'])->toBe($f['backCharge']->number)
        ->and($statement[1]['type'])->toBe('liquidated_damages')
        ->and($statement[1]['reference'])->toBeNull();
});
