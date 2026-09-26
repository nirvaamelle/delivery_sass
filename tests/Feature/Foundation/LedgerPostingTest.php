<?php

use App\Domain\Budgets\BudgetStatus;
use App\Domain\Cutoffs\CutoffClosedException;
use App\Domain\Cutoffs\CutoffNotConfiguredException;
use App\Domain\Cutoffs\CutoffType;
use App\Domain\Posting\CrossOrganizationPostingException;
use App\Domain\Posting\LedgerCategory;
use App\Domain\Posting\LedgerImmutableException;
use App\Domain\Posting\LedgerPoster;
use App\Models\Budget;
use App\Models\CostCode;
use App\Models\CutoffCalendar;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectCostLedgerEntry;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Ledger posting — P0-13
|--------------------------------------------------------------------------
|
| The organising principle of the whole system, from PLAN.md §1:
|
|   "Every process ends in the same place: the project cost ledger."
|
| The fourth and last of §3's cross-cutting services, and the only code
| permitted to write this table. Every posting carries the project code, the
| cost code, and the source document that caused it — which is what makes a
| P&L line traceable back to a receiving report or a payslip.
|
| The ledger is append-only. Not "by convention", not "unless you are an admin":
| the table itself refuses UPDATE and DELETE, so a bad posting is corrected the
| way accounting has always corrected one — with a reversing entry that leaves
| both rows visible.
|
*/

function poster(): LedgerPoster
{
    return app(LedgerPoster::class);
}

/**
 * A project, a cost code in the same organization, and an open OPEX period.
 *
 * @return array{0: Project, 1: CostCode, 2: Budget}
 */
function postable(string $cutoff = '2026-05-26 17:00:00'): array
{
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create(['code' => 'MBI-2026-014']);
    $costCode = CostCode::factory()->for($organization)->create(['code' => '02.10.100']);
    $budget = Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);

    CutoffCalendar::query()->create([
        'project_id' => null,
        'cutoff_type' => CutoffType::Opex,
        'period_start' => '2026-05-01',
        'period_end' => '2026-05-31',
        'cutoff_at' => $cutoff,
    ]);

    return [$project, $costCode, $budget];
}

function postOnce(Project $project, CostCode $costCode, Budget $source, string $amount = '15000.0000'): ProjectCostLedgerEntry
{
    return poster()->post(
        project: $project,
        costCode: $costCode,
        category: LedgerCategory::Overhead,
        amount: $amount,
        sourceDocument: $source,
        documentNumber: 'EXP-2026-00042',
        cutoffType: CutoffType::Opex,
        documentDate: Carbon::parse('2026-05-03'),
        description: 'Site office electricity',
    );
}

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('posts an entry carrying the project code, cost code and source document', function () {
    // PLAN.md §3, verbatim: every posting carries project_code, cost_code,
    // source_document_type and source_document_id. Without the last two a P&L
    // line cannot be traced back to the document that caused it.
    [$project, $costCode, $budget] = postable();

    $entry = postOnce($project, $costCode, $budget);

    expect($entry->project_code)->toBe('MBI-2026-014')
        ->and($entry->cost_code)->toBe('02.10.100')
        ->and($entry->source_document_type)->toBe($budget->getMorphClass())
        ->and($entry->source_document_id)->toBe($budget->getKey())
        ->and($entry->document_number)->toBe('EXP-2026-00042');
});

it('snapshots the codes so renaming a project cannot rewrite history', function () {
    // The codes are stored as values, not only as foreign keys. A ledger that
    // silently restates last year's postings when someone edits a cost code is
    // not an audit trail.
    [$project, $costCode, $budget] = postable();
    $entry = postOnce($project, $costCode, $budget);

    $project->update(['code' => 'MBI-2026-014-REVISED']);
    $costCode->update(['code' => '99.99.999']);

    expect($entry->fresh()->project_code)->toBe('MBI-2026-014')
        ->and($entry->fresh()->cost_code)->toBe('02.10.100');
});

it('holds the posted amount as exact decimal money', function () {
    [$project, $costCode, $budget] = postable();

    $entry = postOnce($project, $costCode, $budget, '87654321098765.4321');

    expectMoney($entry->fresh()->amount);
    expect($entry->fresh()->amount)->toBe('87654321098765.4321');
});

it('refuses a posting made after the cutoff', function () {
    // PLAN.md §5: nothing books after the cutoff date. The Posting service
    // takes the cutoff TYPE as an argument (F3) so it resolves against the
    // right calendar rather than a single global one.
    [$project, $costCode, $budget] = postable();
    Carbon::setTestNow('2026-05-27 09:00:00');

    expect(fn () => postOnce($project, $costCode, $budget))
        ->toThrow(CutoffClosedException::class);
});

it('refuses a posting when no calendar governs the document date', function () {
    $organization = Organization::factory()->create();
    $project = Project::factory()->for($organization)->create();
    $costCode = CostCode::factory()->for($organization)->create();
    $budget = Budget::factory()->for($project)->create();

    expect(fn () => postOnce($project, $costCode, $budget))
        ->toThrow(CutoffNotConfiguredException::class);
});

it('refuses a cost code belonging to another organization', function () {
    // Cross-organization contamination would put one company's cost into
    // another company's P&L, and nothing downstream would ever notice.
    [$project, , $budget] = postable();
    $foreign = CostCode::factory()->for(Organization::factory())->create();

    expect(fn () => postOnce($project, $foreign, $budget))
        ->toThrow(CrossOrganizationPostingException::class);
});

it('refuses a zero-amount posting', function () {
    [$project, $costCode, $budget] = postable();

    expect(fn () => postOnce($project, $costCode, $budget, '0.0000'))
        ->toThrow(InvalidArgumentException::class);
});

it('refuses to update a posted row, at the database', function () {
    // Enforced by a trigger, not by the model. An append-only ledger that only
    // Eloquent respects is not append-only — an importer or a console command
    // goes straight past the model.
    [$project, $costCode, $budget] = postable();
    $entry = postOnce($project, $costCode, $budget);

    expect(fn () => DB::table('project_cost_ledger')
        ->where('id', $entry->getKey())
        ->update(['amount' => '1.0000']))
        ->toThrow(QueryException::class);
});

it('refuses to delete a posted row, at the database', function () {
    [$project, $costCode, $budget] = postable();
    $entry = postOnce($project, $costCode, $budget);

    expect(fn () => DB::table('project_cost_ledger')->where('id', $entry->getKey())->delete())
        ->toThrow(QueryException::class);
});

it('refuses to update a posted row through the model too', function () {
    [$project, $costCode, $budget] = postable();
    $entry = postOnce($project, $costCode, $budget);

    expect(fn () => $entry->update(['description' => 'Changed my mind']))
        ->toThrow(LedgerImmutableException::class);
});

it('corrects a posting with a reversing entry rather than an edit', function () {
    // The answer to "immutable, so how do you fix a mistake". Both rows stay
    // visible and the pair nets to zero, which is what an auditor expects to
    // see rather than a row that quietly changed.
    [$project, $costCode, $budget] = postable();
    $original = postOnce($project, $costCode, $budget, '15000.0000');

    $reversal = poster()->reverse($original, 'Charged to the wrong cost code.');

    expect($reversal->amount)->toBe('-15000.0000')
        ->and($reversal->reverses_entry_id)->toBe($original->getKey())
        ->and($reversal->description)->toContain('Charged to the wrong cost code.')
        ->and($reversal->project_code)->toBe($original->project_code)
        ->and($reversal->cost_code)->toBe($original->cost_code);
});

it('refuses to reverse the same entry twice', function () {
    // Two reversals of one posting would turn a correction into a credit.
    [$project, $costCode, $budget] = postable();
    $original = postOnce($project, $costCode, $budget);

    poster()->reverse($original, 'Wrong cost code.');

    expect(fn () => poster()->reverse($original, 'Wrong again.'))
        ->toThrow(InvalidArgumentException::class);
});

it('totals a project ledger exactly, reversals included', function () {
    // The P&L is this sum. If it drifts, everything built on it drifts.
    [$project, $costCode, $budget] = postable();

    postOnce($project, $costCode, $budget, '10000.5000');
    $wrong = postOnce($project, $costCode, $budget, '2500.2500');
    poster()->reverse($wrong, 'Duplicate capture.');

    expect(poster()->totalFor($project))->toBe('10000.5000');
});
