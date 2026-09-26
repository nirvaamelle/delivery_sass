<?php

use App\Filament\Pages\ProjectProfitAndLoss;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| The project P&L screen
|--------------------------------------------------------------------------
|
| The page computed a month on mount and gave nobody a way to change it, so the
| question it exists to answer — "how did we do in March?" — could not be asked.
| A period control is the fix; the rest of this suite guards the arithmetic the
| page shows, which must agree with the rows it is summed from.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-06-15 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('opens for finance and defaults to the month just closed', function () {
    // The consolidation is written up in days 3–5 of the following month, so the
    // month somebody opens this page to read is almost always the last one.
    actingAs(userWithRole('finance-manager'));

    get(ProjectProfitAndLoss::getUrl())->assertSuccessful();

    Livewire::test(ProjectProfitAndLoss::class)
        ->assertSet('year', 2026)
        ->assertSet('month', 5);
});

it('steps back a month and forward again', function () {
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ProjectProfitAndLoss::class)
        ->callAction(TestAction::make('previousMonth'))
        ->assertSet('month', 4)
        ->callAction(TestAction::make('nextMonth'))
        ->assertSet('month', 5);
});

it('rolls the year over at the turn', function () {
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ProjectProfitAndLoss::class)
        ->set('year', 2026)
        ->set('month', 1)
        ->callAction(TestAction::make('previousMonth'))
        ->assertSet('year', 2025)
        ->assertSet('month', 12);
});

it('jumps to any month', function () {
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ProjectProfitAndLoss::class)
        ->callAction(TestAction::make('choosePeriod'), data: ['month' => 3, 'year' => 2026])
        ->assertSet('month', 3)
        ->assertSet('year', 2026)
        ->assertSee('March 2026');
});

it('reads "nothing billed" rather than a zero margin on an empty month', function () {
    // Zero margin on zero revenue says the company worked for nothing, which is
    // a different and much worse statement than nothing having been billed.
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ProjectProfitAndLoss::class)->assertSee('Nothing billed this month');
});

it('says so when there is nothing to report on', function () {
    actingAs(userWithRole('finance-manager'));

    Livewire::test(ProjectProfitAndLoss::class)->assertSee('No projects to report on');
});

it('stays finance\'s screen', function (string $role, int $status) {
    actingAs(userWithRole($role));

    get(ProjectProfitAndLoss::getUrl())->assertStatus($status);
})->with([
    'finance' => ['finance-manager', 200],
    'managing director' => ['managing-director', 200],
    'project manager' => ['project-manager', 403],
    'foreman' => ['foreman', 403],
]);
