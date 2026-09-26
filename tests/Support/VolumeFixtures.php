<?php

/*
|--------------------------------------------------------------------------
| Volume and query-plan fixtures — P6-03
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md asks for indexes "checked against five years of simulated
| document volume". These are the tools that make that a test rather than an
| afternoon with a stopwatch.
|
| The plan helpers read `EXPLAIN`, not the clock. A timing assertion on a
| developer laptop measures the laptop — it passes on a fast machine with a
| missing index and fails on a slow one with every index in place. What actually
| degrades over five years is a query that reads the whole table, and the
| optimiser says so on twenty rows exactly as it does on two million. The volume
| is there so it stops preferring a scan out of laziness.
|
*/

use App\Domain\Cutoffs\CutoffType;
use App\Domain\Ops\BackupService;
use App\Domain\Posting\LedgerCategory;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

function backups(): BackupService
{
    return app(BackupService::class);
}

/**
 * Composite index columns on a table, in order, as comma-joined strings.
 *
 * Order matters and is the reason this returns the columns rather than the
 * index names: an index on (category, project_id) will not serve a query
 * filtering project_id alone, and a test checking only the name would pass on
 * it.
 *
 * @return array<int, string>
 */
function indexColumnsOn(string $table): array
{
    $rows = DB::select('SHOW INDEX FROM '.$table);
    $byName = [];

    foreach ($rows as $row) {
        $byName[$row->Key_name][(int) $row->Seq_in_index] = $row->Column_name;
    }

    return array_values(array_map(function (array $columns): string {
        ksort($columns);

        return implode(',', $columns);
    }, $byName));
}

/**
 * The chosen plan for a query's first table.
 *
 * @param  array<int, mixed>  $bindings
 * @return array{type: ?string, key: ?string, rows: ?int}
 */
function explainQuery(string $sql, array $bindings = []): array
{
    $row = DB::select('EXPLAIN '.$sql, $bindings)[0];

    return [
        'type' => $row->type ?? null,
        'key' => $row->key ?? null,
        'rows' => isset($row->rows) ? (int) $row->rows : null,
    ];
}

/**
 * Five years of ledger volume, at the rate a mid-sized contractor posts.
 *
 * Written through the query builder in bulk rather than through `LedgerPoster`.
 * That is a deliberate exception to this build's rule about fixtures going
 * through services, and the reason is that the service is not what is under
 * test here — the INDEXES are, and 2,400 postings through a service that opens
 * a transaction and resolves a cutoff calendar each time would make this suite
 * slower than the whole rest of the run.
 *
 * The append-only triggers still hold, and `IndexCoverageTest` asserts they
 * survive this shortcut: an insert is legal, and it is UPDATE and DELETE the
 * ledger refuses.
 *
 * Volume assumption, recorded rather than hidden: four projects, five ledger
 * categories, TWO postings per category per month for sixty months = 2,400. One
 * a month would model a project that issues material once and pays a single
 * subcontractor; the second is what makes a range scan a real choice for the
 * optimiser rather than a formality. PLACEHOLDER: Part D item 13's headcount would let this be
 * sized from the client's actual volume rather than from a plausible one.
 */
function seedLedgerVolume(int $projects = 4, int $months = 60): void
{
    $organizationProjects = Project::withoutProjectScope(fn () => Project::factory()->count($projects)->create());

    $rows = [];
    $now = now()->toDateTimeString();

    foreach ($organizationProjects as $project) {
        $costCode = CostCode::factory()->create(['organization_id' => $project->organization_id]);
        $start = Carbon::parse('2022-01-01');

        for ($month = 0; $month < $months; $month++) {
            $date = $start->copy()->addMonths($month)->endOfMonth();

            foreach (LedgerCategory::cases() as $index => $category) {
                // Twice per category per month. One posting a month per
                // category would model a project that issues material once and
                // pays one subcontractor; the second row is what makes the
                // optimiser's choice on a range scan a real choice.
                foreach ([0, 1] as $occurrence) {
                    $rows[] = [
                        'project_id' => $project->getKey(),
                        'cost_code_id' => $costCode->getKey(),
                        'project_code' => $project->code,
                        'cost_code' => $costCode->code,
                        'source_document_type' => 'App\\Models\\Expense',
                        'source_document_id' => ($month * 10) + $index + ($occurrence * 1000),
                        'document_number' => sprintf('VOL-%s-%04d-%d', $category->value, $month, $occurrence),
                        'category' => $category->value,
                        'amount' => '1000.0000',
                        'cutoff_type' => CutoffType::Billing->value,
                        'document_date' => $date->toDateString(),
                        'posted_at' => $date->toDateTimeString(),
                        'description' => 'Simulated volume',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }
    }

    // Chunked: a single insert of 1,200+ rows exceeds MySQL's default packet on
    // some configurations, and the failure reads as a connection error.
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('project_cost_ledger')->insert($chunk);
    }

    // ANALYZE so the optimiser plans against the volume just loaded rather than
    // against whatever statistics it held before. Without it the plans asserted
    // in this suite are plans for an empty table.
    DB::statement('ANALYZE TABLE project_cost_ledger');
}

/**
 * How many entries the volume seeder produced.
 */
function ledgerVolumeCount(): int
{
    return ProjectCostLedgerEntry::query()->count();
}

/**
 * A project the restore rehearsal can look for on the other side.
 *
 * Written on a SECOND connection, deliberately. `mysqldump` is a separate
 * process, and the suite runs inside `RefreshDatabase`'s transaction — a row
 * created the ordinary way is invisible to it, and the dump would come back
 * without the data while every in-process assertion still passed. Anything a
 * external process has to see must be committed, which is exactly what a
 * backup test is about.
 *
 * The row is removed by `forgetRestorableRows()` in the suite's teardown, since
 * the enclosing transaction will not roll it back either.
 */
function seedRestorableRow(string $code): void
{
    committedConnection()->table('projects')->insert([
        'organization_id' => committedOrganizationId(),
        'code' => $code,
        'name' => 'Restore rehearsal',
        'client_name' => 'Rehearsal client',
        'phase' => 'construction',
        'status' => 'active',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * Remove everything `seedRestorableRow()` committed.
 */
function forgetRestorableRows(): void
{
    committedConnection()->table('projects')->where('name', 'Restore rehearsal')->delete();
    committedConnection()->table('organizations')->where('name', 'Restore rehearsal org')->delete();
}

/**
 * An organization row that is likewise committed, so the project can point at
 * something that exists outside the test transaction.
 */
function committedOrganizationId(): int
{
    $existing = committedConnection()->table('organizations')->where('name', 'Restore rehearsal org')->first();

    if ($existing !== null) {
        return (int) $existing->id;
    }

    return (int) committedConnection()->table('organizations')->insertGetId([
        'code' => 'ORG-REHEARSAL',
        'name' => 'Restore rehearsal org',
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);
}

/**
 * A connection to the test database that is NOT inside the test transaction.
 */
function committedConnection(): Connection
{
    config()->set('database.connections.committed', config('database.connections.mysql'));

    return DB::connection('committed');
}

/**
 * Read something back out of the restored scratch database.
 *
 * A second connection rather than `USE`: switching the live connection's
 * database mid-test is the kind of state that leaks into the next test and
 * fails somewhere else entirely.
 *
 * @return array<int, string>
 */
function scratchQuery(string $database, string $sql): array
{
    config()->set('database.connections.scratch', array_merge(
        config('database.connections.mysql'),
        ['database' => $database],
    ));

    DB::purge('scratch');

    $rows = DB::connection('scratch')->select($sql);

    DB::purge('scratch');

    return array_map(fn (object $row): string => (string) array_values((array) $row)[0], $rows);
}

/**
 * The scratch database the restore rehearsal loads into.
 *
 * A named function rather than a property on the test: the name is fixed, and
 * `BackupService` refuses any target without the marker in it — so the constant
 * that has to carry it belongs somewhere a reader can find it.
 */
function scratchDatabase(): string
{
    return 'construction_scratch_test';
}
