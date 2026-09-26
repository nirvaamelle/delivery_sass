<?php

use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\NoApprovalTierException;
use Database\Seeders\ApprovalMatrixSeeder;

/*
|--------------------------------------------------------------------------
| The authority matrix contract — P1-17, the B2 re-test
|--------------------------------------------------------------------------
|
| PHASE-PLAN.md Part D item 1 — the client's real peso limits — is B2, and it is
| the schedule's critical path. It is still unanswered. Phase 1 was built against
| the deck's bands as explicit placeholders, and P1-17 is the re-test that
| acceptance requires, so it cannot be quietly forgotten.
|
| **This file is that re-test, and it is written to run against numbers nobody
| has supplied yet.** It asserts nothing about 50,000 or 5,000,000 — those are
| the build's invention. It asserts the properties that must hold for ANY set of
| bands the client sends back:
|
|   - the bands are contiguous, so no amount falls into a gap;
|   - they do not overlap, so no amount routes two ways;
|   - the top band is open-ended, so there is no purchase nobody can approve;
|   - every floor and every ceiling routes to the tier it belongs to, because a
|     purchase sitting exactly on a threshold and approved one tier too low is
|     the precise failure the matrix exists to prevent;
|   - sole source escalates exactly one tier and stays put at the top;
|   - tier one keeps a single approver — PLAN.md §9's approval-fatigue
|     mitigation, which a new set of bands could silently undo.
|
| So when B2 lands, the work is: edit `config/approvals.php`, run this file. If
| the client's numbers contain a gap or an overlap, this says so before a single
| requisition routes against them.
|
*/

beforeEach(fn () => (new ApprovalMatrixSeeder)->run());

/**
 * Named for this file rather than `router()`, which the Phase 0 approvals suite
 * already defines. Pest loads every test file into one namespace, so a generic
 * helper name is a collision waiting for the next suite that wants it.
 */
function matrixRouter(): ApprovalRouter
{
    return app(ApprovalRouter::class);
}

/**
 * @return array<int, array{tier: int, floor: string, ceiling: ?string, approver_roles: array<int, string>, required_documents: array<int, string>}>
 */
function configuredTiers(): array
{
    $tiers = config('approvals.tiers', []);

    usort($tiers, fn (array $a, array $b): int => $a['tier'] <=> $b['tier']);

    return $tiers;
}

it('configures at least one tier for every document type that routes', function () {
    expect(config('approvals.document_types'))->not->toBeEmpty()
        ->and(configuredTiers())->not->toBeEmpty();
});

it('leaves no gap between one band and the next', function () {
    // A gap is not a rounding curiosity: an amount that falls in one throws
    // NoApprovalTierException, and the document simply cannot be submitted. The
    // step between bands must be exactly one centavo at the money scale.
    $tiers = configuredTiers();

    for ($i = 0; $i < count($tiers) - 1; $i++) {
        $ceiling = $tiers[$i]['ceiling'];
        $nextFloor = $tiers[$i + 1]['floor'];

        expect($ceiling)->not->toBeNull(
            sprintf('Tier %d has no ceiling but is not the top tier.', $tiers[$i]['tier'])
        );

        expect(bcsub($nextFloor, $ceiling, 4))->toBe('0.0001');
    }
});

it('leaves the top band open ended', function () {
    // A ceiling on the highest tier creates an amount above every band, which
    // routes nowhere — a purchase nobody in the company is able to approve.
    $tiers = configuredTiers();

    expect(end($tiers)['ceiling'])->toBeNull();
});

it('routes every band floor and ceiling to the tier that owns it', function () {
    // The boundary test, run against whatever the bands turn out to be. bccomp
    // rather than float comparison throughout the router, which is what makes
    // the exact-threshold case answerable at all.
    foreach (configuredTiers() as $tier) {
        expect(matrixRouter()->routeFor('purchase_order', $tier['floor'])->tier)
            ->toBe($tier['tier'], sprintf('Floor %s did not route to tier %d.', $tier['floor'], $tier['tier']));

        if ($tier['ceiling'] !== null) {
            expect(matrixRouter()->routeFor('purchase_order', $tier['ceiling'])->tier)
                ->toBe($tier['tier'], sprintf('Ceiling %s did not route to tier %d.', $tier['ceiling'], $tier['tier']));
        }
    }
});

it('routes one centavo above a ceiling to the next tier up', function () {
    // The other side of every boundary. This is the case the deck's whole
    // authority argument rests on, and it is invisible to a happy-path test.
    $tiers = configuredTiers();

    for ($i = 0; $i < count($tiers) - 1; $i++) {
        $justOver = bcadd($tiers[$i]['ceiling'], '0.0001', 4);

        expect(matrixRouter()->routeFor('purchase_order', $justOver)->tier)
            ->toBe($tiers[$i + 1]['tier']);
    }
});

it('escalates a sole-source purchase exactly one tier', function () {
    $tiers = configuredTiers();

    for ($i = 0; $i < count($tiers) - 1; $i++) {
        $tier = $tiers[$i];

        expect(matrixRouter()->routeFor('purchase_order', $tier['floor'], soleSource: true)->tier)
            ->toBe($tiers[$i + 1]['tier']);
    }
});

it('holds a sole-source purchase at the top tier rather than routing it nowhere', function () {
    // Stated as a decision rather than left to look like an oversight: there is
    // no tier above the highest one, so escalation stops. Falling through to
    // "no tier" would make the largest sole-source purchase the only one that
    // cannot be approved at all.
    $tiers = configuredTiers();
    $top = end($tiers);

    expect(matrixRouter()->routeFor('purchase_order', $top['floor'], soleSource: true)->tier)
        ->toBe($top['tier']);
});

it('keeps tier one to a single approver', function () {
    // PLAN.md §9 names approval fatigue at tier one as a risk and states the
    // mitigation explicitly. A new set of bands from the client could silently
    // undo it — this is what notices.
    $first = configuredTiers()[0];

    expect($first['approver_roles'])->toHaveCount(1);
});

it('requires more documents as the authority rises', function () {
    // The deck's tiers are not just bigger numbers, they are heavier evidence:
    // a canvass at the bottom, a board approval at the top. A configuration
    // where a higher tier asks for less is a mistake in the client's answer.
    $previous = 0;

    foreach (configuredTiers() as $tier) {
        expect(count($tier['required_documents']))->toBeGreaterThanOrEqual($previous);
        $previous = count($tier['required_documents']);
    }
});

it('refuses an amount below the lowest floor rather than inventing a tier', function () {
    // Only reachable if the client's lowest band does not start at zero. A
    // negative or sub-floor amount must throw rather than route to tier one,
    // because the alternative is approving something the matrix never covered.
    $lowest = configuredTiers()[0]['floor'];

    if (bccomp($lowest, '0.0000', 4) <= 0) {
        expect(true)->toBeTrue();

        return;
    }

    expect(fn () => matrixRouter()->routeFor('purchase_order', bcsub($lowest, '0.0001', 4)))
        ->toThrow(NoApprovalTierException::class);
});
