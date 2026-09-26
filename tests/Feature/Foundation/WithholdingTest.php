<?php

use App\Domain\Tax\UnknownWithholdingCode;
use App\Domain\Tax\WithholdingCalculator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Withholding tax foundation — P0-09, closes F9
|--------------------------------------------------------------------------
|
| F9: withholding rate, withheld amount and certificate reference belong on
| every money-bearing document from its FIRST migration, not bolted on later.
| Retrofitting a tax column onto a table that already has postings means
| restating them, so the shape is fixed here and Phase 1 and 2 adopt it.
|
| PLACEHOLDER: Part D item 14. The rates below are the Philippine EWT defaults
| the deck's peso and BIR references imply. They are configuration and the
| client is expected to overwrite them — but the SHAPE of the calculation, and
| the fact that it never touches a float, is not up for negotiation.
|
*/

function withholding(): WithholdingCalculator
{
    return app(WithholdingCalculator::class);
}

beforeEach(function () {
    config()->set('withholding.rates', [
        'goods' => '0.010000',
        'services' => '0.020000',
        'professional' => '0.100000',
    ]);
});

it('computes the withheld amount at the configured rate', function () {
    expect(withholding()->compute('10000.0000', 'services'))->toBe('200.0000');
});

it('returns a withheld amount that honours the money contract', function () {
    expectMoney(withholding()->compute('8765.4321', 'services'));
});

it('stays exact on an amount that would lose digits as a float', function () {
    // 12345678901234.5678 carries 18 significant digits; a double holds about
    // 15. Doing this in floating point silently drops the tail, and PLAN.md §4
    // is explicit that money never becomes a float.
    expect(withholding()->compute('12345678901234.5678', 'services'))
        ->toBe('246913578024.6914');
});

it('rounds half up at the fourth decimal', function () {
    // 333.33335 * 1% = 3.3333335 -> 3.3333; 333.3333 * 10% = 33.33333 -> 33.3333
    expect(withholding()->compute('1666.6675', 'goods'))->toBe('16.6667');
});

it('reports the net payable after withholding', function () {
    expect(withholding()->netOf('10000.0000', 'services'))->toBe('9800.0000');
});

it('rejects a withholding code that is not configured', function () {
    // A silent fallback to zero would under-withhold and leave the company
    // liable for the difference.
    expect(fn () => withholding()->compute('10000.0000', 'crypto'))
        ->toThrow(UnknownWithholdingCode::class);
});

it('rejects a negative base amount', function () {
    expect(fn () => withholding()->compute('-100.0000', 'services'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a base amount that is not a number', function () {
    expect(fn () => withholding()->compute('1,000.00', 'services'))
        ->toThrow(InvalidArgumentException::class);
});

it('ships a default rate table for every code it documents', function () {
    // The shipped config, not the test override — every rate must be a decimal
    // string, never a float literal, or the exactness is lost before the
    // calculator ever sees it.
    $rates = require config_path('withholding.php');

    expect($rates['rates'])->not->toBeEmpty();

    foreach ($rates['rates'] as $code => $rate) {
        expect($rate)->toBeString("rate for {$code} must be a string, not a float");
        expect($rate)->toMatch('/^\d+\.\d{6}$/');
    }
});

/*
|--------------------------------------------------------------------------
| The schema convention
|--------------------------------------------------------------------------
|
| One macro, so every money-bearing document adopts identical columns. Asserted
| against a Blueprint rather than a live table because the documents that carry
| these columns — ap_vouchers, sales_invoices — arrive in Phase 1 and 2.
|
*/

it('adds the four withholding columns through one schema macro', function () {
    // The schema grammar is resolved lazily; a Blueprint built off a
    // connection that has never run a schema operation has none yet.
    $connection = DB::connection();
    $connection->useDefaultSchemaGrammar();

    $blueprint = new Blueprint($connection, 'ap_vouchers');

    $blueprint->withholdingColumns();

    $columns = collect($blueprint->getColumns())->keyBy('name');

    expect($columns->keys()->all())->toBe([
        'withholding_code',
        'withholding_rate',
        'withholding_amount',
        'withholding_certificate_reference',
    ]);
});

it('gives the withheld amount the same precision as every other money column', function () {
    // The schema grammar is resolved lazily; a Blueprint built off a
    // connection that has never run a schema operation has none yet.
    $connection = DB::connection();
    $connection->useDefaultSchemaGrammar();

    $blueprint = new Blueprint($connection, 'ap_vouchers');

    $blueprint->withholdingColumns();

    // ColumnDefinition is a Fluent, so its attributes are dynamic. Array
    // access is the defined way to read them; `->total` only works by magic.
    $amount = collect($blueprint->getColumns())
        ->where('name', 'withholding_amount')
        ->sole();

    expect($amount['type'])->toBe('decimal')
        ->and($amount['total'])->toBe(18)
        ->and($amount['places'])->toBe(4);
});
