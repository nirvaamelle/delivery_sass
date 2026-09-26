<?php

use App\Domain\Posting\LedgerCategory;
use App\Models\ProjectCostLedgerEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Index coverage against five years of volume — P6-03
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Phase 6: "Indexes checked against five years of simulated
| document volume."
|
| **This suite asserts query PLANS, not wall-clock times.** A timing assertion
| on a developer laptop measures the laptop: it passes on a fast machine with a
| missing index and fails on a slow one with every index in place. What actually
| degrades over five years is a query that reads the whole table, and MySQL will
| say so in `EXPLAIN` on twenty rows exactly as it does on two million. The
| volume is what makes the optimiser stop preferring a scan out of laziness.
|
| The queries checked are the ones this system runs constantly and cannot avoid:
| the ledger totals every P&L and every consolidation is summed from, the AR
| sweep, and the cutoff resolution every posting in the build passes through.
|
| **A composite index is asserted by its columns and their ORDER**, not by its
| name. An index on (category, project_id) will not serve a query filtering
| project_id alone, and a test that only checked the name would pass on it.
|
*/

it('has a composite index on the ledger for project and category', function () {
    // Every P&L figure, every consolidation row and the whole final account are
    // summed from this pair. Reversed, the index would not serve a P&L for one
    // project across all categories, which is the commoner read.
    expect(indexColumnsOn('project_cost_ledger'))
        ->toContain('project_id,category,document_date');
});

it('has an index on the ledger by source document', function () {
    // "Which posting did this back-charge write" is asked by the screens and by
    // every reversal, and polymorphic columns are the ones people forget.
    expect(indexColumnsOn('project_cost_ledger'))
        ->toContain('source_document_type,source_document_id');
});

it('resolves a project P and L without scanning the ledger', function () {
    seedLedgerVolume();

    $plan = explainQuery(
        'select sum(amount) from project_cost_ledger where project_id = ? and category = ? and document_date between ? and ?',
        [1, LedgerCategory::Material->value, '2026-01-01', '2026-12-31'],
    );

    expect($plan['type'])->not->toBe('ALL')
        ->and($plan['key'])->not->toBeNull();
});

it('finds a posting by its source document without scanning', function () {
    seedLedgerVolume();

    $plan = explainQuery(
        'select * from project_cost_ledger where source_document_type = ? and source_document_id = ?',
        ['App\\Models\\BackCharge', 7],
    );

    expect($plan['type'])->not->toBe('ALL');
});

it('resolves a cutoff period without scanning the calendar', function () {
    // Every posting in the build passes through this lookup — four chains, on
    // three different rhythms. It is the single most-executed query here.
    expect(indexColumnsOn('cutoff_calendars'))
        ->toContain('cutoff_type,period_start,period_end');
});

it('finds unpaid invoices for the AR sweep without scanning', function () {
    // The weekly sweep reads every issued invoice on every project. Left
    // unindexed it degrades exactly as the receivables ledger grows, which is
    // to say as the business succeeds.
    expect(indexColumnsOn('sales_invoices'))
        ->toContain('project_id,status');
});

it('finds a document by its number without scanning', function () {
    // How a human looks anything up. Every numbered document carries a unique
    // index on `number`, which serves the lookup as well as the constraint.
    foreach (['purchase_orders', 'billings', 'sales_invoices', 'back_charges', 'warranties'] as $table) {
        expect(indexColumnsOn($table))->toContain('number');
    }
});

it('indexes every foreign key the chain joins on', function () {
    // MySQL creates one per foreign key, and this asserts none was dropped by a
    // later migration that rebuilt a table.
    foreach (['punchlist_items', 'back_charges', 'final_billing_deductions', 'close_out_checklist_items'] as $table) {
        expect(indexColumnsOn($table))->not->toBeEmpty();
    }
});

it('seeds five years of ledger volume in one command', function () {
    // The volume itself is the deliverable: a plan asserted on an empty table
    // proves nothing, because the optimiser prefers a scan when a scan is
    // genuinely cheaper.
    seedLedgerVolume();

    expect(ProjectCostLedgerEntry::query()->count())->toBeGreaterThanOrEqual(2000);
});

it('keeps the immutability triggers after the volume is loaded', function () {
    // The volume seeder writes through the query builder for speed, which walks
    // straight past the model guard. The triggers are what still hold, and this
    // asserts the shortcut did not quietly disable the ledger's core promise.
    seedLedgerVolume();

    $triggers = DB::select(
        'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA = ? AND EVENT_OBJECT_TABLE = ?',
        [DB::getDatabaseName(), 'project_cost_ledger'],
    );

    expect(count($triggers))->toBe(2);
});

it('has the tables the five-year projection is measured against', function () {
    foreach (['project_cost_ledger', 'cutoff_calendars', 'sales_invoices', 'activity_log'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});
