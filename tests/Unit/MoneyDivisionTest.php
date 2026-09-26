<?php

use App\Domain\Support\Money;

/*
|--------------------------------------------------------------------------
| Division arrives with logistics — spec §6.3
|--------------------------------------------------------------------------
|
| Money has summed, multiplied and rounded since the first migration; nothing
| had needed to divide. km/L, lines per hour and pick accuracy are all
| divisions, and all three would otherwise be written as floats in three
| different services. One home, one rounding rule.
|
*/

it('divides decimal strings at the requested scale', function () {
    expect(Money::divide('250', '45', 3))->toBe('5.556');
});

it('divides at the money scale by default', function () {
    expect(Money::divide('100', '8'))->toBe('12.5000');
});

it('divides negative values without rounding toward zero', function () {
    expect(Money::divide('-250', '45', 3))->toBe('-5.556');
});

it('refuses a zero divisor rather than returning infinity', function () {
    Money::divide('250', '0', 3);
})->throws(InvalidArgumentException::class);

it('refuses a float dressed as a divisor', function () {
    Money::divide('250', 'abc', 3);
})->throws(InvalidArgumentException::class);
