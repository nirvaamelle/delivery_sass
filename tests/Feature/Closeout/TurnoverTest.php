<?php

use App\Domain\Closeout\TurnoverItemSource;
use App\Models\TurnoverPack;
use App\Models\TurnoverPackItem;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Turnover and acceptance — P5-04
|--------------------------------------------------------------------------
|
| Slide 9 step 3, owned by PMO and the client, and it sits between punchlist
| clearing and final billing: "substantial completion → punchlist clearing →
| turnover and acceptance → final billing → demobilization → project close-out."
| The order is the design. Acceptance is refused while the punchlist is open,
| because a client accepting a site with defects outstanding accepts the defects
| with it.
|
| Slide 9's documents to close: "as-built drawings and manuals, certificate of
| completion, warranty certificates and permits."
|
| **The pack has two kinds of line, and that is the whole point of the task.**
|
| A FILED line — as-built drawings, the manuals, the certificate of completion —
| is satisfied by somebody putting a reference on file and signing for it. It is
| slide 9's "named clearer per line" applied one level down.
|
| A DERIVED line — warranty certificates, permits — is answered by the register
| and cannot be typed at all. A pack that let a clerk tick "warranty
| certificates: on file" while subcontracts sit in P5-03's register with no
| certificate against them is the honour system F4 objected to, rebuilt one
| phase later. So the tick is not offered: the line reads the register, and it
| names the subcontracts nobody chased.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Assembling the pack
|--------------------------------------------------------------------------
*/

it('assembles a turnover pack from the template, one line per document', function () {
    [$project, $certificate] = turnoverProject();

    $pack = turnovers()->assemble($certificate, User::factory()->create());

    expect($pack->number)->toStartWith('TOP-2026-')
        ->and((int) $pack->project_id)->toBe($project->getKey())
        ->and($pack->items()->pluck('document_key')->all())->toBe([
            'as_built_drawings',
            'operation_manuals',
            'certificate_of_completion',
            'warranty_certificates',
            'permits',
        ])
        ->and($pack->accepted_at)->toBeNull();
});

it('marks the register-answered lines as derived, not filed', function () {
    [, $certificate] = turnoverProject();

    $pack = turnovers()->assemble($certificate, User::factory()->create());

    expect($pack->items()->where('document_key', 'as_built_drawings')->sole()->source)
        ->toBe(TurnoverItemSource::Filed)
        ->and($pack->items()->where('document_key', 'warranty_certificates')->sole()->source)
        ->toBe(TurnoverItemSource::WarrantyRegister)
        ->and($pack->items()->where('document_key', 'permits')->sole()->source)
        ->toBe(TurnoverItemSource::PermitRegister);
});

it('refuses a second turnover pack on one project', function () {
    // Two packs is two acceptance dates, and everything downstream — final
    // billing, the defects liability clock — dates from one of them.
    [, $certificate] = turnoverProject();
    turnovers()->assemble($certificate, User::factory()->create());

    expect(fn () => turnovers()->assemble($certificate, User::factory()->create()))
        ->toThrow(DomainException::class, 'already has a turnover pack');
});

/*
|--------------------------------------------------------------------------
| Filing a document
|--------------------------------------------------------------------------
*/

it('files a document against a line and names who filed it', function () {
    [$pack] = assembledPack();
    $qs = User::factory()->create();

    $item = turnovers()->file($pack, 'as_built_drawings', 'DWG-AB-2026-114', $qs);

    expect($item->reference)->toBe('DWG-AB-2026-114')
        ->and((int) $item->filed_by_user_id)->toBe($qs->getKey())
        ->and($item->filed_at)->not->toBeNull();
});

it('refuses a document the pack does not ask for', function () {
    // Otherwise the requirement is satisfiable by filing anything at all: the
    // count passes and the evidence is still absent.
    [$pack] = assembledPack();

    expect(fn () => turnovers()->file($pack, 'site_photographs', 'IMG-001', User::factory()->create()))
        ->toThrow(DomainException::class, 'does not ask for');
});

it('refuses to file a line the register answers', function () {
    // The tick is not on offer. "Warranty certificates: on file" typed by hand
    // is exactly the honour system the register exists to replace.
    [$pack] = assembledPack();

    expect(fn () => turnovers()->file($pack, 'warranty_certificates', 'All collected', User::factory()->create()))
        ->toThrow(DomainException::class, 'answered by the register');
});

it('refuses a document filed with no reference', function () {
    [$pack] = assembledPack();

    expect(fn () => turnovers()->file($pack, 'operation_manuals', '   ', User::factory()->create()))
        ->toThrow(DomainException::class, 'needs a reference');
});

it('refuses to file the same line twice', function () {
    // Filing is not editing. The second signature overwrites the first, and the
    // close-out report names the wrong person for a document they never saw.
    [$pack] = assembledPack();
    turnovers()->file($pack, 'as_built_drawings', 'DWG-AB-2026-114', User::factory()->create());

    expect(fn () => turnovers()->file($pack, 'as_built_drawings', 'DWG-AB-2026-115', User::factory()->create()))
        ->toThrow(DomainException::class, 'already on file');
});

it('refuses to file anything once the client has accepted', function () {
    // The pack that was accepted is the pack the client saw.
    [$pack] = acceptedPack();

    expect(fn () => turnovers()->file($pack->fresh(), 'as_built_drawings', 'DWG-LATE', User::factory()->create()))
        ->toThrow(DomainException::class, 'already accepted');
});

it('refuses a register-answered line filed by hand, at the database', function () {
    // The distinction between a filed line and a derived one cannot rest on one
    // service method an importer never calls.
    [$pack] = assembledPack();
    $derived = $pack->items()->where('document_key', 'warranty_certificates')->sole();

    expect(fn () => TurnoverPackItem::query()->whereKey($derived->getKey())->update([
        'reference' => 'All collected',
        'filed_at' => now(),
        'filed_by_user_id' => User::factory()->create()->getKey(),
    ]))->toThrow(QueryException::class);
});

it('refuses a filed row with no filer, at the database', function () {
    [$pack] = assembledPack();
    $item = $pack->items()->where('document_key', 'as_built_drawings')->sole();

    expect(fn () => TurnoverPackItem::query()->whereKey($item->getKey())->update([
        'reference' => 'DWG-AB-2026-114',
        'filed_at' => now(),
        'filed_by_user_id' => null,
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| What is still outstanding
|--------------------------------------------------------------------------
*/

it('names every outstanding line, not just the first', function () {
    // A PMO clearing one blocker only to be shown the next, one round trip at a
    // time, is how a turnover date slips by a fortnight.
    [$pack] = assembledPack();

    expect(count(turnovers()->missingFor($pack)))->toBe(4);
});

it('does not block on a line the template marks optional', function () {
    config()->set('closeout.turnover_pack', [
        ['key' => 'certificate_of_completion', 'label' => 'Certificate of completion', 'required' => true, 'source' => 'filed'],
        ['key' => 'spare_parts_list', 'label' => 'Spare parts list', 'required' => false, 'source' => 'filed'],
    ]);

    [$pack] = assembledPack();
    turnovers()->file($pack, 'certificate_of_completion', 'COC-2026-004', User::factory()->create());

    expect(turnovers()->missingFor($pack))->toBe([])
        ->and(turnovers()->isComplete($pack))->toBeTrue();
});

it('answers the warranty line from the register, and names what nobody chased', function () {
    [$pack, $project] = assembledPack();
    $steel = subcontractOn($project, 'SUB-STEEL');

    expect(turnovers()->missingFor($pack))
        ->toContain('Warranty certificates (no certificate on file for '.$steel->number.')');

    warranties()->register(
        $project, $steel->vendor()->sole(), 'ACME-WC-8841', 'Structural steel',
        Carbon::parse('2026-05-18'), Carbon::parse('2027-05-18'), User::factory()->create(),
        subcontract: $steel,
    );

    expect(turnovers()->missingFor($pack))->not->toContain('Warranty certificates');
});

it('answers the permit line from the register', function () {
    [$pack, $project] = assembledPack();

    expect(turnovers()->missingFor($pack))->toContain('Permits and clearances');

    occupancyPermitFor($project);

    expect(turnovers()->missingFor($pack))->not->toContain('Permits and clearances');
});

/*
|--------------------------------------------------------------------------
| Acceptance
|--------------------------------------------------------------------------
*/

it('accepts a complete pack and names the client representative', function () {
    [$pack, $project] = completePack();

    $accepted = turnovers()->accept($pack, User::factory()->create(), 'A. Reyes, Project Director');

    expect($accepted->accepted_at)->not->toBeNull()
        ->and($accepted->client_representative)->toBe('A. Reyes, Project Director')
        ->and(turnovers()->isAccepted($accepted))->toBeTrue()
        ->and(turnovers()->forProject($project->fresh())?->getKey())->toBe($pack->getKey());
});

it('reports who filed each line and when', function () {
    // Slide 9's close-out report, for one pack: "the close-out report names who
    // cleared each item and when." P5-08 assembles the project-wide version out
    // of rows shaped like this one.
    [$pack] = assembledPack();
    $qs = User::factory()->create();
    turnovers()->file($pack, 'as_built_drawings', 'DWG-AB-2026-114', $qs);

    $report = collect(turnovers()->filingReport($pack))->keyBy('document');

    expect($report['As-built drawings']['filed_by'])->toBe($qs->name)
        ->and($report['As-built drawings']['reference'])->toBe('DWG-AB-2026-114')
        ->and($report['Warranty certificates']['filed_by'])->toBeNull()
        ->and($report['Warranty certificates']['source'])->toBe('warranty_register');
});

it('refuses acceptance while a required document is missing', function () {
    [$pack] = assembledPack();

    expect(fn () => turnovers()->accept($pack, User::factory()->create(), 'A. Reyes'))
        ->toThrow(DomainException::class, 'cannot be accepted');
});

it('refuses acceptance while the punchlist is still open', function () {
    // Slide 9's order, enforced. A client accepting a site with defects
    // outstanding accepts the defects with it.
    [$pack] = completePack(closePunchlist: false);

    expect(fn () => turnovers()->accept($pack, User::factory()->create(), 'A. Reyes'))
        ->toThrow(DomainException::class, 'is still open');
});

it('refuses acceptance on a project whose punchlist was never issued', function () {
    // "No open items" is true of a list nobody walked — the same absence-is-not
    // -permission rule P5-01 turned on.
    [$project, $certificate] = completedProject();
    $pack = turnovers()->assemble($certificate, User::factory()->create());
    fileEveryDocument($pack);
    occupancyPermitFor($project);

    expect(fn () => turnovers()->accept($pack, User::factory()->create(), 'A. Reyes'))
        ->toThrow(DomainException::class, 'no punchlist');
});

it('refuses acceptance signed by nobody in particular', function () {
    [$pack] = completePack();

    expect(fn () => turnovers()->accept($pack, User::factory()->create(), '  '))
        ->toThrow(DomainException::class, 'client representative');
});

it('refuses an acceptance dated forward', function () {
    [$pack] = completePack();

    expect(fn () => turnovers()->accept(
        $pack, User::factory()->create(), 'A. Reyes', Carbon::parse('2026-06-30'),
    ))->toThrow(DomainException::class, 'has not happened yet');
});

it('refuses to accept a pack twice', function () {
    [$pack] = acceptedPack();

    expect(fn () => turnovers()->accept($pack->fresh(), User::factory()->create(), 'B. Santos'))
        ->toThrow(DomainException::class, 'already accepted');
});

it('refuses an accepted row with no client representative, at the database', function () {
    // Slide 9 again: acceptance is a named act, not a status flag. A control
    // that lives only in a service is not a control.
    [$pack] = completePack();

    expect(fn () => TurnoverPack::query()->whereKey($pack->getKey())->update([
        'accepted_at' => now(),
        'accepted_by_user_id' => User::factory()->create()->getKey(),
        'client_representative' => null,
    ]))->toThrow(QueryException::class);
});
