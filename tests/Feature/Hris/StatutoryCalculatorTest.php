<?php

/*
|--------------------------------------------------------------------------
| Statutory contributions and withholding — P3-08
|--------------------------------------------------------------------------
|
| PLACEHOLDER: Part D item 9 — "statutory deductions: jurisdiction and whose
| computation tables we follow." Unanswered, so these tests pin the BEHAVIOUR of
| the tables in config/payroll.php rather than vouching for the figures in them.
|
| What they do vouch for is the shape every one of these schedules has, and the
| three places a payroll routine usually gets them wrong: the SSS salary-credit
| ROUNDING, the floor and ceiling on each base, and the boundary of each
| withholding bracket. When the client's payroll officer supplies the real
| tables, these tests are what say the arithmetic still holds.
|
| Every method takes MONTHLY compensation and returns the share for ONE cutoff,
| because the contributions are monthly obligations and the deck pays twice a
| month.
|
*/

it('rounds compensation to the nearest SSS salary credit step', function () {
    // 24,260 sits in the bracket whose credit is 24,500; 24,240 in the one below.
    // A routine that truncates instead of rounding under-contributes on half of
    // every bracket, and the shortfall is the employee's benefit, not the
    // company's saving.
    expect(statutory()->sss('24260.0000'))->toBe('612.5000')
        ->and(statutory()->sss('24240.0000'))->toBe('600.0000');
});

it('applies the SSS floor and ceiling', function () {
    expect(statutory()->sss('3000.0000'))->toBe('125.0000')
        ->and(statutory()->sss('80000.0000'))->toBe('875.0000');
});

it('applies the PhilHealth floor and ceiling', function () {
    expect(statutory()->philhealth('24000.0000'))->toBe('300.0000')
        ->and(statutory()->philhealth('8000.0000'))->toBe('125.0000')
        ->and(statutory()->philhealth('150000.0000'))->toBe('1250.0000');
});

it('caps Pag-IBIG at the maximum fund salary', function () {
    expect(statutory()->pagibig('24000.0000'))->toBe('100.0000')
        ->and(statutory()->pagibig('6000.0000'))->toBe('60.0000');
});

it('withholds nothing at or below the first bracket', function () {
    // The boundary itself is inside the zero bracket: tax applies to the amount
    // OVER it, and a routine that taxes on reaching it takes a centavo from
    // every minimum earner who lands exactly on the line.
    expect(statutory()->withholding('10000.0000'))->toBe('0.0000')
        ->and(statutory()->withholding('10417.0000'))->toBe('0.0000');
});

it('taxes only the excess over the bracket floor, plus the bracket fixed amount', function () {
    // 11,000: 15% of the 583 over 10,417 is 87.45.
    // 20,000: 937.50 fixed plus 20% of the 3,333 over 16,667 is 1,604.10.
    expect(statutory()->withholding('11000.0000'))->toBe('87.4500')
        ->and(statutory()->withholding('20000.0000'))->toBe('1604.1000');
});

it('withholds on pay after contributions, not on gross', function () {
    // The order is the rule. Contributions come off first and tax is computed on
    // what is left — taxing gross would withhold on money the employee never
    // received, every cutoff, from everybody.
    $breakdown = statutory()->breakdown('12000.0000');

    expect($breakdown['sss'])->toBe('600.0000')
        ->and($breakdown['philhealth'])->toBe('300.0000')
        ->and($breakdown['pagibig'])->toBe('100.0000')
        ->and($breakdown['taxable'])->toBe('11000.0000')
        ->and($breakdown['withholding'])->toBe('87.4500')
        ->and($breakdown['total'])->toBe('1087.4500');
});
