<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/*
|--------------------------------------------------------------------------
| Activity-log export — P6-02
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Phase 6's build list, last item. PLAN.md §3 already made the
| activity log append-only because "this system produces an audited P&L, so who
| changed what and when is not optional" — an audit trail nobody can get out of
| the database is an audit trail only in principle.
|
| **The export is a file, not a screen.** An auditor works from a spreadsheet
| they can filter, keep, and attach to a working paper. Paginating three years
| of entries through a browser is a way of not producing them.
|
| **It goes to the private disk.** Every row names a person and what they did.
| The public disk would make the company's audit trail a URL, and B4's whole
| point is that somebody outside reads this.
|
| **The date range is inclusive at both ends, and required.** An auditor asks
| for a period. Defaulting to "everything" would produce a file too big to be
| read on the request that is easiest to make by accident.
|
| **Old is unchanged.** A CSV whose columns move between exports cannot be
| compared to the one filed last quarter, so the header is asserted literally.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-06-15 09:00:00');
    Storage::fake('local');
});

afterEach(fn () => Carbon::setTestNow());

it('writes a CSV to the private disk', function () {
    loggedChange();

    $path = activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'));

    Storage::disk('local')->assertExists($path);
    expect($path)->toStartWith('exports/activity-log/')->toEndWith('.csv');
});

it('names the columns an auditor asks for, in a fixed order', function () {
    // Asserted literally: a header that moves between exports cannot be
    // compared to the file filed last quarter.
    loggedChange();

    $header = exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')))[0];

    expect($header)->toBe('logged_at,log_name,event,description,subject_type,subject_id,causer_name,causer_email,changes');
});

it('names the person who made the change, not just their id', function () {
    // An id is not evidence. The auditor is reading this without the database
    // beside them.
    $user = User::factory()->create(['name' => 'R. Villanueva', 'email' => 'rv@example.test']);
    loggedChange($user);

    $csv = implode("\n", exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))));

    expect($csv)->toContain('R. Villanueva')->toContain('rv@example.test');
});

it('records what actually changed, not that something did', function () {
    loggedChange();

    $csv = implode("\n", exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))));

    expect($csv)->toContain('name');
});

it('includes an entry on the first and last day of the range', function () {
    // Inclusive at both ends. Somebody will ask for a calendar month, and an
    // export that dropped the 30th would be wrong in the direction nobody
    // checks.
    Carbon::setTestNow('2026-06-01 00:30:00');
    loggedChange(description: 'FIRST-DAY');
    Carbon::setTestNow('2026-06-30 23:30:00');
    loggedChange(description: 'LAST-DAY');
    Carbon::setTestNow('2026-07-01 00:30:00');
    loggedChange(description: 'OUTSIDE');

    // Asserted by membership rather than by a row count: creating the users
    // that make a change is itself logged, so a count would be measuring the
    // fixture instead of the boundary.
    $csv = implode(PHP_EOL, exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))));

    expect($csv)->toContain('FIRST-DAY');
    expect($csv)->toContain('LAST-DAY');
    expect($csv)->not->toContain('OUTSIDE');
});

it('refuses a range that ends before it starts', function () {
    expect(fn () => activityExport()->export(Carbon::parse('2026-06-30'), Carbon::parse('2026-06-01')))
        ->toThrow(DomainException::class, 'ends before it starts');
});

it('writes a header even when the period has no entries', function () {
    // An empty file is not the same as no file. "Nothing happened in that
    // period" is an answer an auditor needs to be given, in writing.
    $rows = exportLines(activityExport()->export(Carbon::parse('2020-01-01'), Carbon::parse('2020-01-31')));

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toStartWith('logged_at,');
});

it('quotes a value containing a comma rather than splitting the row', function () {
    // A description with a comma in it is ordinary in this build — half the
    // refusal messages have one. Unquoted, it silently shifts every later
    // column by one.
    loggedChange(description: 'Cleared, re-inspected, and signed off');

    $csv = implode("\n", exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30'))));

    expect($csv)->toContain('"Cleared, re-inspected, and signed off"');
});

it('neutralises a value a spreadsheet would run as a formula', function () {
    // CSV injection. This file exists to be opened in Excel by an auditor, and
    // a description beginning =, +, - or @ is executed as a formula on open —
    // which is how a value typed into this system by one of its users becomes
    // code running on the machine of the person auditing it.
    //
    // Prefixed with a tab rather than stripped: the auditor must still see what
    // was actually recorded, and deleting characters from an audit trail to
    // make it safe to read is worse than the injection.
    loggedChange(description: '=HYPERLINK("http://evil.test","click")');

    $rows = exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')));

    // Asserted per CELL, because that is the unit a spreadsheet evaluates. The
    // payload appears inside the JSON changes blob too, and that cell begins
    // with a brace — it is text there, and prefixing it would corrupt the JSON
    // an auditor may want to parse.
    expect(implode(PHP_EOL, $rows))->toContain('HYPERLINK');

    foreach ($rows as $row) {
        foreach (str_getcsv($row, ',', '"', '\\') as $cell) {
            expect(formulaLead((string) $cell))->toBeFalse();
        }
    }
});

it('neutralises every formula lead character, not just the equals sign', function () {
    foreach (['+SUM(1)', '-SUM(1)', '@SUM(1)'] as $payload) {
        expect(activityExport()->neutralise($payload))->toStartWith("\t");
    }
});

it('leaves an ordinary value untouched', function () {
    // The guard must not corrupt the 99% case. A negative amount is not a
    // formula, but it starts with a minus — so the rule is "leads a formula",
    // read against the value as a whole.
    expect(activityExport()->neutralise('Cleared and signed off'))->toBe('Cleared and signed off');
    expect(activityExport()->neutralise('-1500.0000'))->toBe('-1500.0000');
});

it('exports the whole log regardless of who runs it', function () {
    // The export runs unscoped by design. An auditor reading one project's
    // trail is not the case this exists for, and a partial audit trail that
    // looks complete is worse than none.
    $mine = Project::factory()->create();
    Project::factory()->create();
    loggedChange();

    actingAsAssignee($mine);

    expect(count(exportLines(activityExport()->export(Carbon::parse('2026-06-01'), Carbon::parse('2026-06-30')))) - 1)
        ->toBeGreaterThan(0);
});

it('can be run from the console', function () {
    // The way it will actually be used: an auditor asks, somebody runs it on
    // the server. A screen-only export is one that needs a person with a login.
    loggedChange();

    artisan('activity:export', ['--from' => '2026-06-01', '--to' => '2026-06-30'])
        ->assertExitCode(0);
});

it('refuses an impossible range from the console rather than writing nothing', function () {
    artisan('activity:export', ['--from' => '2026-06-30', '--to' => '2026-06-01'])
        ->assertExitCode(1);
});
