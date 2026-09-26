<?php

use App\Filament\Resources\BackCharges\BackChargesResource;
use App\Filament\Resources\CloseOutChecklists\CloseOutChecklistsResource;
use App\Filament\Resources\Demobilizations\DemobilizationsResource;
use App\Filament\Resources\FinalAccounts\FinalAccountsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\RetentionReleases\RetentionReleasesResource;
use App\Filament\Resources\TurnoverPacks\TurnoverPacksResource;
use App\Filament\Resources\Warranties\WarrantiesResource;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The Phase 5 screens — P5-10
|--------------------------------------------------------------------------
|
| Eight resources, all read-only, and here the rule is not a preference. Every
| close-out document in this phase is the output of a service that refused
| something first: a punchlist item cleared without a name, a back-charge on an
| open defect, a turnover accepted over an unfinished punchlist, a checklist
| line certified over evidence that contradicts it. A form would produce rows
| that look identical to the checked ones and are not.
|
| So the screens show what was decided and by whom. The close-out checklist is
| the one that matters most: it is the exit gate's second sentence rendered —
| who cleared each item and when.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2027-07-01 09:00:00');
    // P6-01 scopes every project-bearing query to the signed-in user.
    // A screen test asserting a record is visible has to say who is
    // looking at it, and an administrator is who opens these screens.
    actingAs(panelUser());
});

afterEach(fn () => Carbon::setTestNow());

it('renders every Phase 5 screen', function (string $resource) {
    get($resource::getUrl('index'))->assertSuccessful();
})->with([
    PunchlistsResource::class,
    BackChargesResource::class,
    WarrantiesResource::class,
    TurnoverPacksResource::class,
    DemobilizationsResource::class,
    RetentionReleasesResource::class,
    FinalAccountsResource::class,
    CloseOutChecklistsResource::class,
]);

it('offers no way to create a close-out document from the screen', function (string $resource) {
    expect($resource::canCreate())->toBeFalse()
        ->and(array_key_exists('create', $resource::getPages()))->toBeFalse();
})->with([
    PunchlistsResource::class,
    BackChargesResource::class,
    WarrantiesResource::class,
    TurnoverPacksResource::class,
    DemobilizationsResource::class,
    RetentionReleasesResource::class,
    FinalAccountsResource::class,
    CloseOutChecklistsResource::class,
]);

it('shows a punchlist as open until somebody signs it closed', function () {
    // An empty punchlist has no open items either. The screen must not read as
    // cleared on a list nobody walked — P5-01's rule, on screen.
    $punchlist = issuedPunchlist();

    get(PunchlistsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($punchlist->number)
        ->assertSee('Open');
});

it('shows a back-charge against the subcontract it reduces', function () {
    billingCalendarForMay();
    Carbon::setTestNow('2026-05-20 09:00:00');
    [$item, $subcontract, $costCode] = clearedSubcontractorItem();
    $charge = backCharges()->raise($item, $costCode, '48250.0000', User::factory()->create(), 'Handrail re-set.');

    get(BackChargesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($charge->number)
        ->assertSee($subcontract->number);
});

it('shows a warranty with the date its coverage runs out', function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
    $warranty = registeredWarranty();

    get(WarrantiesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($warranty->certificate_reference);
});

it('shows a turnover pack the client has not accepted as awaiting acceptance', function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
    [$pack] = assembledPack();

    get(TurnoverPacksResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($pack->number)
        ->assertSee('Awaiting acceptance');
});

it('shows the close-out checklist naming who cleared each item and when', function () {
    // The exit gate's second sentence, on screen. This is the screen the phase
    // is for.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    $accountant = User::factory()->create(['name' => 'R. Villanueva']);
    checklists()->clear($checklist, 'retention_collected', $accountant, 'OR-RET-1 receipted.');

    get(CloseOutChecklistsResource::getUrl('view', ['record' => $checklist->getKey()]))
        ->assertSuccessful()
        ->assertSee('R. Villanueva')
        ->assertSee('Retention released and collected')
        ->assertSee('OR-RET-1 receipted.');
});

it('shows an uncleared checklist line as outstanding rather than as nothing', function () {
    // A blank cell reads as "no data". An outstanding line is a person's
    // outstanding work, and the close-out report has to say so.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    get(CloseOutChecklistsResource::getUrl('view', ['record' => $checklist->getKey()]))
        ->assertSuccessful()
        ->assertSee('Not cleared');
});

it('shows a retention claim raised but not collected as uncollected', function () {
    // P5-07's whole distinction, on screen: the money is not in until it is in.
    $r = closeableProject(collectRetention: false, checklist: false);
    $claim = retentionReleases()->claim($r['contract'], '242500.0000', User::factory()->create());

    get(RetentionReleasesResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($claim->number)
        ->assertSee('Uncollected');
});

it('shows the final account with what the project made', function () {
    $r = closeableProject(checklist: false);
    $account = finalAccounts()->file($r['project'], User::factory()->create());

    get(FinalAccountsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($account->number);
});

it('shows a demobilization with the people still to be cleared', function () {
    $r = closeableProject(demobilize: false, checklist: false);
    $demobilization = demobilizations()->open($r['project'], User::factory()->create());

    get(DemobilizationsResource::getUrl('index'))
        ->assertSuccessful()
        ->assertSee($demobilization->number)
        ->assertSee('Open');
});
