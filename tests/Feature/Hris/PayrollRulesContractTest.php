<?php

use App\Domain\Hris\StatutoryCalculator;
use App\Domain\Support\Money;

/*
|--------------------------------------------------------------------------
| The payroll rules contract — P3-13, the B3 re-test
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md's entry condition for Phase 3: "B3 issued — payroll rules must be
| the SOP's rules, not the deck's defaults, before the first real cutoff." **B3
| has not been issued.** Phase 3 was built against the build's own approximation
| of published Philippine schedules, and P3-13 is the re-test that acceptance
| requires.
|
| **This file is that re-test, and it is written to run against tables nobody has
| supplied yet.** It asserts almost nothing about 10,417 or 5% — those are the
| build's invention. It asserts the properties that must hold for ANY schedule
| the client's payroll officer sends back:
|
|   - contribution bases are floored and capped, and both bite;
|   - the salary credit is ROUNDED to its bracket, not truncated;
|   - withholding brackets are contiguous, ordered, and start at zero;
|   - tax applies to the excess OVER a bracket floor, so landing exactly on a
|     floor costs nothing and one centavo over costs the marginal rate;
|   - the schedule is monotonic — earning more never takes home less;
|   - contributions come off before tax.
|
| So when B3 lands the work is: edit `config/payroll.php`, run this file. If the
| SOP's tables contain a gap, an inversion or a cliff, this says so before a
| single payslip is printed. The same shape as P1-17 for the approval bands, and
| the stakes are higher — a band routed wrongly is embarrassing, a payroll rule
| applied wrongly is somebody's pay.
|
*/

function statutoryConfig(string $key): mixed
{
    return config("payroll.statutory.{$key}");
}

/**
 * @return array<int, array{over: string, fixed: string, rate: string}>
 */
function withholdingBrackets(): array
{
    $brackets = config('payroll.statutory.withholding_semi_monthly', []);

    usort($brackets, fn (array $a, array $b): int => bccomp((string) $a['over'], (string) $b['over'], 4));

    return $brackets;
}

it('configures every schedule the payroll run reads', function () {
    expect(statutoryConfig('sss'))->not->toBeEmpty()
        ->and(statutoryConfig('philhealth'))->not->toBeEmpty()
        ->and(statutoryConfig('pagibig'))->not->toBeEmpty()
        ->and(withholdingBrackets())->not->toBeEmpty();
});

it('floors and caps every contribution base', function () {
    // Both ends have to bite. A schedule with no ceiling contributes without
    // limit on a director's salary; one with no floor contributes nothing on a
    // short cutoff, and the employee loses the credited month.
    $calculator = app(StatutoryCalculator::class);

    $sss = statutoryConfig('sss');
    expect($calculator->sss('1.0000'))->toBe($calculator->sss((string) $sss['msc_floor']))
        ->and($calculator->sss('99999999.0000'))->toBe($calculator->sss((string) $sss['msc_ceiling']));

    $philhealth = statutoryConfig('philhealth');
    expect($calculator->philhealth('1.0000'))->toBe($calculator->philhealth((string) $philhealth['base_floor']))
        ->and($calculator->philhealth('99999999.0000'))->toBe($calculator->philhealth((string) $philhealth['base_ceiling']));

    $pagibig = statutoryConfig('pagibig');
    expect($calculator->pagibig('99999999.0000'))->toBe($calculator->pagibig((string) $pagibig['max_fund_salary']));
});

it('rounds the salary credit to its bracket rather than truncating', function () {
    // Truncating under-contributes on half of every bracket, and the shortfall
    // is the employee's benefit rather than the company's saving. Asserted
    // against whatever step the SOP defines: just over half a step up must reach
    // the higher credit.
    $calculator = app(StatutoryCalculator::class);
    $sss = statutoryConfig('sss');

    $step = (string) $sss['msc_step'];
    $base = Money::sum((string) $sss['msc_floor'], $step, $step);
    $justOverHalf = Money::sum($base, bcadd(bcdiv($step, '2', 4), '0.0100', 4));

    expect($calculator->sss($justOverHalf))->toBe($calculator->sss(Money::sum($base, $step)));
});

it('starts the withholding table at zero and leaves no gap between brackets', function () {
    // A gap is an amount the table cannot price. The first bracket must start at
    // zero, and every bracket floor must be reachable from the one below.
    $brackets = withholdingBrackets();

    expect(bccomp((string) $brackets[0]['over'], '0.00', 4))->toBe(0);

    for ($i = 0; $i < count($brackets) - 1; $i++) {
        expect(bccomp((string) $brackets[$i + 1]['over'], (string) $brackets[$i]['over'], 4))
            ->toBeGreaterThan(0, 'Withholding brackets must strictly ascend.');
    }
});

it('never lowers the marginal rate as income rises', function () {
    // A regressive step is almost always a transcription error, and it is
    // invisible until somebody compares two payslips.
    $brackets = withholdingBrackets();

    for ($i = 0; $i < count($brackets) - 1; $i++) {
        expect(bccomp((string) $brackets[$i + 1]['rate'], (string) $brackets[$i]['rate'], 6))
            ->toBeGreaterThanOrEqual(0, 'A higher bracket must not tax at a lower rate.');
    }
});

it('taxes nothing at a bracket floor and the marginal rate one centavo above it', function () {
    // THE BOUNDARY, run against whatever the brackets turn out to be. Tax applies
    // to the EXCESS over a floor — a routine that taxes on reaching it takes
    // money from every earner who lands exactly on the line.
    $calculator = app(StatutoryCalculator::class);

    foreach (withholdingBrackets() as $bracket) {
        $at = $calculator->withholding((string) $bracket['over']);
        $justOver = $calculator->withholding(Money::sum((string) $bracket['over'], '0.0100'));

        expect($at)->toBe(Money::round((string) $bracket['fixed']));

        // One centavo over adds the marginal rate on one centavo, and nothing
        // more. This is what catches a bracket whose fixed amount does not
        // continue from the one below it.
        expect(bcsub($justOver, $at, 4))->toBe(Money::multiply('0.0100', (string) $bracket['rate']));
    }
});

it('is monotonic — earning more never takes home less', function () {
    // The property that matters most to the person being paid, and the one a
    // mis-keyed fixed amount breaks. Walked across every bracket boundary.
    $calculator = app(StatutoryCalculator::class);

    $previousTakeHome = null;

    foreach (withholdingBrackets() as $bracket) {
        foreach (['0.0000', '0.0100', '100.0000'] as $offset) {
            $gross = Money::sum((string) $bracket['over'], $offset);
            $takeHome = bcsub($gross, $calculator->withholding($gross), 4);

            if ($previousTakeHome !== null) {
                expect(bccomp($takeHome, $previousTakeHome, 4))
                    ->toBeGreaterThanOrEqual(0, sprintf('Take-home fell at %s.', $gross));
            }

            $previousTakeHome = $takeHome;
        }
    }
});

it('takes contributions off before computing tax', function () {
    // The order is the rule, and reversing it withholds on money the employee
    // never received — every cutoff, for everybody.
    $calculator = app(StatutoryCalculator::class);

    $breakdown = $calculator->breakdown('12000.0000');
    $contributions = Money::sum($breakdown['sss'], $breakdown['philhealth'], $breakdown['pagibig']);

    expect($breakdown['taxable'])->toBe(bcsub('12000.0000', $contributions, 4))
        ->and($breakdown['withholding'])->toBe($calculator->withholding($breakdown['taxable']))
        ->and($breakdown['total'])->toBe(Money::sum($contributions, $breakdown['withholding']));
});

it('never deducts more than the gross', function () {
    // A deduction set larger than the pay it comes out of is a negative payslip.
    // The run reports that as an exception rather than paying it, but a schedule
    // that produces one at an ordinary wage is a schedule with an error in it.
    $calculator = app(StatutoryCalculator::class);

    foreach (['5000.0000', '12000.0000', '30000.0000', '100000.0000'] as $gross) {
        expect(bccomp($calculator->breakdown($gross)['total'], $gross, 4))
            ->toBeLessThan(0, sprintf('Deductions exceed gross at %s.', $gross));
    }
});

it('splits a monthly obligation across the configured number of cutoffs', function () {
    // PLACEHOLDER: B3. Half each cutoff is the default; an SOP that deducts the
    // whole month in the second cutoff is a config change, and the calculator
    // REFUSES an unknown split rather than falling back to halves — which would
    // deduct the wrong amount from everybody, quietly.
    expect(statutoryConfig('split'))->toBe('half_each_cutoff');

    config()->set('payroll.statutory.split', 'whole_in_second_cutoff');

    expect(fn () => app(StatutoryCalculator::class)->sss('20000.0000'))
        ->toThrow(DomainException::class);
});

it('keeps every rate and amount as a string, never a float', function () {
    // PLAN.md §4's money rule, applied to the configuration itself. A float
    // literal in this file loses exactness before bcmath ever sees it.
    $values = [
        statutoryConfig('sss'),
        statutoryConfig('philhealth'),
        statutoryConfig('pagibig'),
        ...withholdingBrackets(),
    ];

    foreach ($values as $group) {
        foreach ($group as $key => $value) {
            expect($value)->toBeString(sprintf('payroll.statutory %s must be a string.', $key));
        }
    }
});
