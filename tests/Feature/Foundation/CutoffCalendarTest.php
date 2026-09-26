<?php

use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Cutoffs\CutoffNotConfiguredException;
use App\Domain\Cutoffs\CutoffResolver;
use App\Domain\Cutoffs\CutoffType;
use App\Models\CutoffCalendar;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Cutoff calendar — P0-08, closes F3
|--------------------------------------------------------------------------
|
| PLAN.md §5: "Nothing books after the cutoff date." F3 makes that resolvable
| rather than ambiguous — the three chains close on different cadences (billing
| monthly, payroll semi-monthly, OPEX on day 26), so a single calendar cannot
| answer the question. The cutoff TYPE is part of the lookup, and a project may
| override the organization-wide row.
|
| Two dates matter and they are not the same date: the document's own date
| decides WHICH period it belongs to, and the moment of posting decides whether
| that period is still open. An expense dated 3 May posted on 27 May is late
| against a day-26 OPEX cutoff even though its own date is early in the month.
|
*/

function resolver(): CutoffResolver
{
    return app(CutoffResolver::class);
}

function calendar(CutoffType $type, string $start, string $end, string $cutoff, ?Project $project = null): CutoffCalendar
{
    return CutoffCalendar::query()->create([
        'project_id' => $project?->getKey(),
        'cutoff_type' => $type,
        'period_start' => $start,
        'period_end' => $end,
        'cutoff_at' => $cutoff,
    ]);
}

it('resolves the organization-wide calendar when a project has no override', function () {
    $project = Project::factory()->create();
    $default = calendar(CutoffType::Opex, '2026-05-01', '2026-05-31', '2026-05-26 17:00:00');

    $resolved = resolver()->resolve(CutoffType::Opex, Carbon::parse('2026-05-03'), $project);

    expect($resolved->getKey())->toBe($default->getKey());
});

it('prefers a project override over the organization-wide calendar', function () {
    $project = Project::factory()->create();
    calendar(CutoffType::Opex, '2026-05-01', '2026-05-31', '2026-05-26 17:00:00');
    $override = calendar(CutoffType::Opex, '2026-05-01', '2026-05-31', '2026-05-28 17:00:00', $project);

    $resolved = resolver()->resolve(CutoffType::Opex, Carbon::parse('2026-05-03'), $project);

    expect($resolved->getKey())->toBe($override->getKey());
});

it('keeps the three cutoff types from colliding', function () {
    // Same period, three cadences. Without cutoff_type in the lookup, a payroll
    // posting would resolve against the OPEX cutoff.
    calendar(CutoffType::Opex, '2026-05-01', '2026-05-31', '2026-05-26 17:00:00');
    calendar(CutoffType::Billing, '2026-05-01', '2026-05-31', '2026-06-05 17:00:00');
    calendar(CutoffType::Payroll, '2026-05-01', '2026-05-15', '2026-05-16 17:00:00');

    $date = Carbon::parse('2026-05-10');

    expect(resolver()->resolve(CutoffType::Opex, $date)->cutoff_at->toDateString())->toBe('2026-05-26')
        ->and(resolver()->resolve(CutoffType::Billing, $date)->cutoff_at->toDateString())->toBe('2026-06-05')
        ->and(resolver()->resolve(CutoffType::Payroll, $date)->cutoff_at->toDateString())->toBe('2026-05-16');
});

it('accepts a posting made before the cutoff moment', function () {
    calendar(CutoffType::Opex, '2026-05-01', '2026-05-31', '2026-05-26 17:00:00');
    Carbon::setTestNow('2026-05-20 09:00:00');

    expect(resolver()->isOpen(CutoffType::Opex, Carbon::parse('2026-05-03')))->toBeTrue();

    Carbon::setTestNow();
});

it('rejects a posting made after the cutoff moment', function () {
    // The control itself. An expense dated early in the month is still late if
    // it is booked after day 26.
    calendar(CutoffType::Opex, '2026-05-01', '2026-05-31', '2026-05-26 17:00:00');
    Carbon::setTestNow('2026-05-27 09:00:00');

    expect(fn () => resolver()->assertOpen(CutoffType::Opex, Carbon::parse('2026-05-03')))
        ->toThrow(CutoffClosedException::class);

    Carbon::setTestNow();
});

it('rejects a posting when no calendar is configured for the period', function () {
    // A missing calendar is a configuration error, not permission to post. The
    // alternative — treating "no calendar" as open — silently defeats the
    // control for exactly the periods nobody set up.
    expect(fn () => resolver()->assertOpen(CutoffType::Payroll, Carbon::parse('2026-05-03')))
        ->toThrow(CutoffNotConfiguredException::class);
});

it('rejects a period that ends before it starts', function () {
    expect(fn () => calendar(CutoffType::Billing, '2026-05-31', '2026-05-01', '2026-06-05 17:00:00'))
        ->toThrow(QueryException::class);
});

it('rejects a second organization-wide calendar for the same type and period', function () {
    // Two default rows for one period make the cutoff ambiguous, and MySQL's
    // unique indexes ignore NULLs — so the uniqueness is enforced over a
    // generated column, not over project_id directly.
    calendar(CutoffType::Billing, '2026-05-01', '2026-05-31', '2026-06-05 17:00:00');

    expect(fn () => calendar(CutoffType::Billing, '2026-05-01', '2026-05-31', '2026-06-06 17:00:00'))
        ->toThrow(QueryException::class);
});
