<?php

use App\Domain\Documents\DocumentLinker;
use App\Models\Budget;
use App\Models\Project;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| Document links — P0-07
|--------------------------------------------------------------------------
|
| The handoff spine. PLAN.md §1: "Every document carries the project code, the
| cost code, and the reference number of the document before it." That last
| clause is this table — predecessor and successor edges between documents,
| which is what turns four separate workflows into one P&L.
|
| The edges are polymorphic because the spine has to span every chain: a PO
| points back at a PR and a bid tabulation, a receiving report at a PO, an AP
| voucher at all three. Phase 1 brings those documents; until then the linker is
| exercised against the models that exist — a budget revision genuinely is a
| predecessor/successor pair, and a budget genuinely derives from a project.
|
*/

function linker(): DocumentLinker
{
    return app(DocumentLinker::class);
}

it('links a predecessor document to a successor', function () {
    $original = Budget::factory()->create(['name' => 'Original budget']);
    $revision = Budget::factory()->create(['name' => 'Revision 1']);

    $link = linker()->link($original, $revision);

    expect($link->predecessor->getKey())->toBe($original->getKey())
        ->and($link->successor->getKey())->toBe($revision->getKey());
});

it('reads the successors of a document', function () {
    $original = Budget::factory()->create();
    $first = Budget::factory()->create();
    $second = Budget::factory()->create();

    linker()->link($original, $first);
    linker()->link($original, $second);

    expect(linker()->successorsOf($original)->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first->id, $second->id])->sort()->values()->all());
});

it('reads the predecessors of a document', function () {
    // A document can have more than one predecessor: PLAN.md §5 gates a PO on
    // an approved PR AND a tabulated bid, so the PO points back at both.
    $project = Project::factory()->create();
    $original = Budget::factory()->create();
    $revision = Budget::factory()->create();

    linker()->link($project, $revision);
    linker()->link($original, $revision);

    expect(linker()->predecessorsOf($revision))->toHaveCount(2);
});

it('spans two different document types', function () {
    $project = Project::factory()->create();
    $budget = Budget::factory()->create();

    linker()->link($project, $budget);

    $predecessor = linker()->predecessorsOf($budget)->sole();

    expect($predecessor)->toBeInstanceOf(Project::class)
        ->and($predecessor->getKey())->toBe($project->getKey());
});

it('traces a document back to its origin', function () {
    // The question the spine exists to answer: where did this document come
    // from, all the way back to the first one in the chain.
    $first = Budget::factory()->create(['name' => 'Original budget']);
    $second = Budget::factory()->create(['name' => 'Revision 1']);
    $third = Budget::factory()->create(['name' => 'Revision 2']);

    linker()->link($first, $second);
    linker()->link($second, $third);

    $ancestors = linker()->ancestorsOf($third)->pluck('name')->all();

    expect($ancestors)->toContain('Original budget')
        ->and($ancestors)->toContain('Revision 1')
        ->and($ancestors)->toHaveCount(2);
});

it('rejects a document linked to itself', function () {
    $budget = Budget::factory()->create();

    expect(fn () => linker()->link($budget, $budget))->toThrow(DomainException::class);
});

it('rejects the same edge recorded twice', function () {
    // A duplicate edge would double-count the document in any trace, and the
    // spine is what the P&L is assembled from.
    $original = Budget::factory()->create();
    $revision = Budget::factory()->create();

    linker()->link($original, $revision);

    expect(fn () => linker()->link($original, $revision))->toThrow(QueryException::class);
});

it('rejects a link that would close a cycle', function () {
    // A cycle makes "trace this document back to its origin" non-terminating,
    // and the trace is the whole point of the table.
    $first = Budget::factory()->create();
    $second = Budget::factory()->create();
    $third = Budget::factory()->create();

    linker()->link($first, $second);
    linker()->link($second, $third);

    expect(fn () => linker()->link($third, $first))->toThrow(DomainException::class);
});
