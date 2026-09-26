<?php

use App\Domain\Documents\DocumentLinker;
use App\Domain\Procurement\InspectionService;
use App\Domain\Procurement\InspectionVerdict;
use App\Models\User;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Inspection and return to vendor — P1-09
|--------------------------------------------------------------------------
|
| Arriving is not the same as being acceptable. The deck's chain runs
| receiving → inspection → issuance, and the gap between the first two is where
| rejected material lives: it is on site, it has been signed for, and it must
| not reach stock or a payment.
|
| The rule that carries this: **accepted + rejected must equal received.**
| Material cannot be quietly lost between the gate and the warehouse, and the
| accepted quantity is what P1-10 issues from and what P1-11 pays against.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

function inspections(): InspectionService
{
    return app(InspectionService::class);
}

it('passes an inspection with everything accepted', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    $inspection = inspections()->inspect($report, [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '500.0000', 'quantity_rejected' => '0.0000'],
    ], User::factory()->create());

    expect($inspection->number)->toStartWith('INS-2026-')
        ->and($inspection->verdict)->toBe(InspectionVerdict::Passed);
});

it('records a partial rejection', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    $inspection = inspections()->inspect($report, [
        [
            'receiving_report_line_id' => $line->getKey(),
            'quantity_accepted' => '480.0000',
            'quantity_rejected' => '20.0000',
            'rejection_reason' => 'Twenty bags hardened in transit.',
        ],
    ], User::factory()->create());

    expect($inspection->verdict)->toBe(InspectionVerdict::PartiallyRejected)
        ->and($inspection->lines()->sole()->quantity_rejected)->toBe('20.0000');
});

it('refuses an inspection whose accepted and rejected do not equal what arrived', function () {
    // THE RULE. 480 accepted and 10 rejected out of 500 leaves ten bags that
    // exist on site and in no record — which is how material walks.
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    expect(fn () => inspections()->inspect($report, [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '480.0000', 'quantity_rejected' => '10.0000'],
    ], User::factory()->create()))->toThrow(DomainException::class);
});

it('refuses a rejection with no reason', function () {
    // A rejection the vendor cannot answer is a dispute waiting to happen, and
    // the scorecard in P1-14 counts rejections — an uncounted reason is an
    // uncountable one.
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    expect(fn () => inspections()->inspect($report, [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '480.0000', 'quantity_rejected' => '20.0000'],
    ], User::factory()->create()))->toThrow(InvalidArgumentException::class);
});

it('raises a return to vendor for the rejected quantity', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    $inspection = inspections()->inspect($report, [
        [
            'receiving_report_line_id' => $line->getKey(),
            'quantity_accepted' => '480.0000',
            'quantity_rejected' => '20.0000',
            'rejection_reason' => 'Hardened in transit.',
        ],
    ], User::factory()->create());

    $rtv = inspections()->returnToVendor($inspection, 'Collected by supplier driver.');

    expect($rtv->number)->toStartWith('RTV-2026-')
        ->and($rtv->quantity_returned)->toBe('20.0000');
});

it('refuses a return to vendor when nothing was rejected', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    $inspection = inspections()->inspect($report, [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '500.0000', 'quantity_rejected' => '0.0000'],
    ], User::factory()->create());

    expect(fn () => inspections()->returnToVendor($inspection, 'Nothing to return.'))
        ->toThrow(DomainException::class);
});

it('reports the accepted quantity, which is what may be issued and paid', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    inspections()->inspect($report, [
        [
            'receiving_report_line_id' => $line->getKey(),
            'quantity_accepted' => '480.0000',
            'quantity_rejected' => '20.0000',
            'rejection_reason' => 'Hardened.',
        ],
    ], User::factory()->create());

    expect(inspections()->acceptedFor($report->fresh()))->toBe('480.0000');
});

it('refuses a second inspection of one receiving report', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    inspections()->inspect($report, [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '500.0000', 'quantity_rejected' => '0.0000'],
    ], User::factory()->create());

    expect(fn () => inspections()->inspect($report->fresh(), [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '500.0000', 'quantity_rejected' => '0.0000'],
    ], User::factory()->create()))->toThrow(DomainException::class);
});

it('marks an inspection rejected outright when nothing is accepted', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    $inspection = inspections()->inspect($report, [
        [
            'receiving_report_line_id' => $line->getKey(),
            'quantity_accepted' => '0.0000',
            'quantity_rejected' => '500.0000',
            'rejection_reason' => 'Wrong grade entirely.',
        ],
    ], User::factory()->create());

    expect($inspection->verdict)->toBe(InspectionVerdict::Rejected);
});

it('links the inspection to its receiving report on the handoff spine', function () {
    [, $report] = receivedGoods();
    $line = $report->lines()->sole();

    $inspection = inspections()->inspect($report, [
        ['receiving_report_line_id' => $line->getKey(), 'quantity_accepted' => '500.0000', 'quantity_rejected' => '0.0000'],
    ], User::factory()->create());

    $predecessors = app(DocumentLinker::class)->predecessorsOf($inspection);

    expect($predecessors)->toHaveCount(1)
        ->and($predecessors->first()->getKey())->toBe($report->getKey());
});
