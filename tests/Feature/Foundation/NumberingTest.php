<?php

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Numbering — P0-06
|--------------------------------------------------------------------------
|
| The first of the four cross-cutting services in PLAN.md §3: gapless,
| per-document-type, per-year sequences (PR-2026-00142), taken from a locked
| counter row inside the same transaction as the document insert.
|
| Never MAX(id) + 1. Two clerks raising a PR in the same second against a
| MAX-based scheme get the same number, and a document number that is not
| unique breaks the handoff spine that every chain hangs off.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-14 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function numbering(): DocumentNumberGenerator
{
    return app(DocumentNumberGenerator::class);
}

it('issues the first number of the year for a document type', function () {
    expect(numbering()->next('PR'))->toBe('PR-2026-00001');
});

it('issues consecutive numbers with no gaps', function () {
    $issued = [numbering()->next('PR'), numbering()->next('PR'), numbering()->next('PR')];

    expect($issued)->toBe(['PR-2026-00001', 'PR-2026-00002', 'PR-2026-00003']);
});

it('keeps a separate sequence per document type', function () {
    numbering()->next('PR');
    numbering()->next('PR');

    expect(numbering()->next('PO'))->toBe('PO-2026-00001')
        ->and(numbering()->next('PR'))->toBe('PR-2026-00003');
});

it('restarts the sequence at the turn of the year', function () {
    numbering()->next('PR');

    Carbon::setTestNow('2027-01-01 00:00:01');

    expect(numbering()->next('PR'))->toBe('PR-2027-00001');
});

it('counts from a stored counter row rather than the documents table', function () {
    // Proves the number does not come from MAX(id) + 1: the counter is a row,
    // and it advances whether or not any document was written.
    numbering()->next('PR');
    numbering()->next('PR');

    $sequence = DocumentSequence::query()
        ->where('document_type', 'PR')
        ->where('year', 2026)
        ->sole();

    expect($sequence->next_number)->toBe(3);
});

it('takes a row lock when it reads the counter', function () {
    // PLAN.md §3: a LOCKED counter row. Without FOR UPDATE two concurrent
    // requests read the same value and issue the same document number.
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower($query->sql);
    });

    numbering()->next('PR');

    $locked = array_filter(
        $statements,
        fn (string $sql): bool => str_contains($sql, 'document_sequences')
            && str_contains($sql, 'for update')
    );

    expect($locked)->not->toBeEmpty();
});

it('does not consume a number when the document insert rolls back', function () {
    // The gapless property that actually matters. The counter moves inside the
    // caller's transaction, so a failed document insert takes the number back
    // with it instead of burning a hole in the sequence.
    numbering()->next('PR');

    try {
        DB::transaction(function () {
            numbering()->next('PR');

            throw new RuntimeException('document insert failed');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(numbering()->next('PR'))->toBe('PR-2026-00002');
});

it('rejects a blank document type', function () {
    expect(fn () => numbering()->next('   '))->toThrow(InvalidArgumentException::class);
});

it('rejects a document type that is not a bare uppercase prefix', function () {
    // The prefix ends up in every printed document and every handoff reference.
    // A stray dash or lowercase letter makes the number unparseable later.
    expect(fn () => numbering()->next('pr-1'))->toThrow(InvalidArgumentException::class);
});
