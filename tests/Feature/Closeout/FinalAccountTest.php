<?php

use App\Models\FinalAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The final account — P5-09
|--------------------------------------------------------------------------
|
| Slide 9's financial close, last line: "final project P&L", and PHASE-PLAN.md
| adds "forecast to completion; vendor and subcontractor scorecards filed."
|
| **Life to date, not a month.** P4-08's `profitAndLoss()` answers "what did this
| project do in May", which is the question a monthly consolidation asks. The
| close-out question is different — what did the project make, over all of it —
| and a report that could only be read a month at a time would be summed by hand
| into a spreadsheet nobody can audit.
|
| **Filed, not derived.** This is the one figure in the phase that is stored
| rather than recomputed, and the reason is narrow: the P&L half IS reproducible,
| because the ledger is append-only, but the FORECAST half depends on open
| commitments that keep moving. A close-out report whose numbers change when
| somebody cancels a purchase order next quarter is not a record of what the
| project made. So filing is a named act with a snapshot behind it.
|
| **Scorecards are checkable.** "Filed" means every vendor and subcontractor who
| worked on the project has one — and the refusal names them, because a count is
| not something a procurement officer can act on.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2027-07-01 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| The P&L, over the whole project
|--------------------------------------------------------------------------
*/

it('sums the P&L over the life of the project, not one month', function () {
    // Revenue posts at the invoice in May 2026; the back-charge is subcontract
    // cost in the same month. Read as at 2027, a monthly P&L would show nothing
    // at all.
    $r = closeableProject(checklist: false);

    $pl = finalAccounts()->profitAndLoss($r['project']);

    expect($pl['revenue'])->toBe('2425000.0000')
        ->and($pl['subcontract'])->toBe('48250.0000')
        ->and($pl['total_cost'])->toBe('48250.0000')
        ->and($pl['gross_profit'])->toBe('2376750.0000');
});

it('states the margin as a percentage of revenue', function () {
    $r = closeableProject(checklist: false);

    expect(finalAccounts()->profitAndLoss($r['project'])['margin_percent'])->toBe('98.01');
});

it('reports a project with no revenue as no margin rather than dividing by zero', function () {
    $project = constructionProject();

    expect(finalAccounts()->profitAndLoss($project)['margin_percent'])->toBe('0.00');
});

/*
|--------------------------------------------------------------------------
| Forecast to completion
|--------------------------------------------------------------------------
*/

it('forecasts the final outturn from the contract sum and what is committed', function () {
    // 48,500,000 contract; 2,425,000 of it billed as the final milestone. What
    // is left to bill is real: this fixture bills only the final 5%.
    $r = closeableProject(checklist: false);

    $forecast = finalAccounts()->forecastToCompletion($r['project']);

    expect($forecast['contract_sum'])->toBe('48500000.0000')
        ->and($forecast['revenue_to_date'])->toBe('2425000.0000')
        ->and($forecast['revenue_remaining'])->toBe('46075000.0000')
        ->and($forecast['cost_to_date'])->toBe('48250.0000')
        ->and($forecast['committed_cost'])->toBe('0.0000')
        ->and($forecast['forecast_final_cost'])->toBe('48250.0000');
});

it('counts an undelivered purchase order as cost still to come', function () {
    // Committed is not cost yet — no ledger row exists until the goods arrive —
    // but a forecast that ignored it would under-read the final outturn by the
    // exact amount somebody has already promised to spend.
    $r = closeableProject(checklist: false);
    $order = undeliveredOrderOn($r['project'], '124500.0000');

    $forecast = finalAccounts()->forecastToCompletion($r['project']->fresh());

    expect($forecast['committed_cost'])->toBe('124500.0000')
        ->and($forecast['forecast_final_cost'])->toBe('172750.0000')
        ->and($order->project_id)->toBe($r['project']->getKey());
});

it('does not let revenue remaining go negative on an over-billed contract', function () {
    // Over-billing happens — a variation billed before the contract sum was
    // revised. A negative "remaining" would read as money owed back.
    $r = closeableProject(checklist: false);
    $r['contract']->update(['contract_sum' => '1000000.0000']);

    expect(finalAccounts()->forecastToCompletion($r['project']->fresh())['revenue_remaining'])->toBe('0.0000');
});

/*
|--------------------------------------------------------------------------
| Filing it
|--------------------------------------------------------------------------
*/

it('files the final account, naming who filed it and freezing the figures', function () {
    $r = closeableProject(checklist: false);
    $accountant = User::factory()->create();

    $account = finalAccounts()->file($r['project'], $accountant, 'Final account agreed with the PMO.');

    expect($account->number)->toStartWith('FA-2027-')
        ->and($account->revenue)->toBe('2425000.0000')
        ->and($account->gross_profit)->toBe('2376750.0000')
        ->and($account->forecast_final_cost)->toBe('48250.0000')
        ->and((int) $account->filed_by_user_id)->toBe($accountant->getKey());
});

it('keeps the filed figures when the forecast afterwards moves', function () {
    // The reason it is stored at all. A close-out report whose numbers change
    // because somebody raised an order next quarter is not a record of what the
    // project made.
    $r = closeableProject(checklist: false);
    $account = finalAccounts()->file($r['project'], User::factory()->create());

    undeliveredOrderOn($r['project'], '124500.0000');

    expect($account->fresh()->forecast_final_cost)->toBe('48250.0000')
        ->and(finalAccounts()->forecastToCompletion($r['project']->fresh())['forecast_final_cost'])
        ->toBe('172750.0000');
});

it('refuses a second final account on one project', function () {
    $r = closeableProject(checklist: false);
    finalAccounts()->file($r['project'], User::factory()->create());

    expect(fn () => finalAccounts()->file($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'already has a final account');
});

it('refuses to file while a vendor on the project has no scorecard', function () {
    // Slide 9 puts the scorecards in the same panel, and the final account is
    // the document that closes it. Filing over an unrated subcontractor loses
    // the only assessment anybody will make of them.
    $r = closeableProject(checklist: false, rateVendors: false);

    expect(fn () => finalAccounts()->file($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'no scorecard');
});

it('names the unrated vendor rather than reporting a count', function () {
    $r = closeableProject(checklist: false, rateVendors: false);

    expect(fn () => finalAccounts()->file($r['project'], User::factory()->create()))
        ->toThrow(DomainException::class, $r['subcontractor']->code);
});

it('refuses a filed row with nobody against it, at the database', function () {
    $r = closeableProject(checklist: false);
    $account = finalAccounts()->file($r['project'], User::factory()->create());

    expect(fn () => FinalAccount::query()->whereKey($account->getKey())->update([
        'filed_by_user_id' => null,
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| What the checklist reads
|--------------------------------------------------------------------------
*/

it('reports every vendor on the project that nobody rated', function () {
    $r = closeableProject(checklist: false, rateVendors: false);

    expect(finalAccounts()->unratedVendors($r['project'])->pluck('code')->all())
        ->toContain($r['subcontractor']->code);
});

it('stops reporting a vendor once a scorecard exists', function () {
    $r = closeableProject(checklist: false);

    expect(finalAccounts()->unratedVendors($r['project']))->toBeEmpty();
});

it('refuses to certify the final P&L line while no final account is filed', function () {
    // The upgrade P5-08 left a placeholder for: this line said `manual` because
    // the build held no fact to check it against, and now it does.
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'final_project_pl', User::factory()->create(), 'Filed.'))
        ->toThrow(DomainException::class, 'no final account');
});

it('certifies the final P&L line once the account is filed', function () {
    $r = closeableProject(checklist: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());
    finalAccounts()->file($r['project'], User::factory()->create());

    expect(checklists()->clear($checklist, 'final_project_pl', User::factory()->create(), 'FA filed.')->cleared_at)
        ->not->toBeNull();
});

it('refuses to certify the scorecards line while a vendor is unrated', function () {
    $r = closeableProject(checklist: false, rateVendors: false);
    $checklist = checklists()->open($r['project'], User::factory()->create());

    expect(fn () => checklists()->clear($checklist, 'scorecards_filed', User::factory()->create(), 'All filed.'))
        ->toThrow(DomainException::class, $r['subcontractor']->code);
});
