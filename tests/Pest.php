<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests bind to the application TestCase and refresh the database.
| The suite runs against MySQL (`construction_test`), not SQLite — PLAN.md §2
| picks MySQL for `SELECT … FOR UPDATE` on gapless document numbering, so the
| numbering and gate tests have to exercise real InnoDB row locking.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| The money contract
|--------------------------------------------------------------------------
|
| Money in this system is DECIMAL(18,4) — never float, never integer cents.
| `expectMoney()` asserts a value survives that contract as a string, so a test
| that accidentally compares floats fails loudly instead of passing by luck.
|
| Written as a plain function rather than a Pest custom expectation. An
| expectation registered at runtime through `expect()->extend()` is invisible to
| PHPStan: the first test to call it reports "undefined method", and the only
| ways to quiet that are the suppressions this build does not allow. A money
| rule that static analysis cannot see is a money rule that gets broken quietly,
| which is the opposite of what PLAN.md §4 is asking for.
|
*/

function expectMoney(mixed $value): void
{
    expect($value)->toBeString();
    expect($value)->toMatch('/^-?\d+\.\d{4}$/');
}
