<?php

use App\Models\Demobilization;
use App\Models\DemobilizationClearance;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Demobilization and clearance — P5-06
|--------------------------------------------------------------------------
|
| Slide 9's third closing panel, "People and assets": clearance and final pay,
| equipment and IT asset return. (The scorecards in that same panel are P5-09.)
|
| **Two kinds of line again, and for a different reason than P5-04's.**
|
| A person is a NAMED ACT. Somebody clears each individual for final pay, signs
| for it, and the close-out report says who and when — slide 9's rule, and the
| whole reason a clearance is a row rather than a count.
|
| Assets and money are DERIVED. Equipment still on site is a question the
| equipment register already answers, and an unliquidated cash advance is one
| P4-02's register answers. Neither can be ticked here: a demobilization that
| let somebody assert "all plant returned" while assignments sit open would be
| a second, softer copy of a fact that already exists — and the soft copy is the
| one people fill in on the last day.
|
| **The cross-chain rule this task exists to enforce:** a person holding an
| unliquidated advance cannot be cleared for final pay. That is the moment the
| company has any leverage to recover it, and F17's day-26 sweep charges the
| next payroll — of which, for a leaver, there may not be one.
|
*/

beforeEach(function () {
    Carbon::setTestNow('2026-05-20 09:00:00');
});

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Opening the demobilization
|--------------------------------------------------------------------------
*/

it('opens a demobilization with a clearance line for everybody who worked on the project', function () {
    $d = demobilisable();

    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect($demob->number)->toStartWith('DEMOB-2026-')
        ->and($demob->clearances()->pluck('employee_id')->all())->toBe([$d['employee']->getKey()]);
});

it('refuses to open a demobilization before the client accepted turnover', function () {
    // Slide 9's order. Releasing the site before the client has accepted it
    // leaves nobody there to answer for what they find.
    [$pack, $project] = completePack();

    expect(fn () => demobilizations()->open($project, User::factory()->create()))
        ->toThrow(DomainException::class, 'has not been accepted')
        ->and($pack->accepted_at)->toBeNull();
});

it('refuses a second demobilization on one project', function () {
    $d = demobilisable();
    demobilizations()->open($d['project'], User::factory()->create());

    expect(fn () => demobilizations()->open($d['project'], User::factory()->create()))
        ->toThrow(DomainException::class, 'already being demobilized');
});

/*
|--------------------------------------------------------------------------
| Clearing a person
|--------------------------------------------------------------------------
*/

it('clears a person for final pay and names who cleared them', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    $hr = User::factory()->create();

    $clearance = demobilizations()->clear($demob, $d['employee'], $hr, 'Tools returned, ID surrendered, no accountabilities.');

    expect($clearance->cleared_at)->not->toBeNull()
        ->and((int) $clearance->cleared_by_user_id)->toBe($hr->getKey());
});

it('refuses to clear a person holding an unliquidated cash advance', function () {
    // The cross-chain rule. Final pay is the last moment the company can
    // recover it, and for a leaver there is no next payroll for F17 to charge.
    $d = demobilisable(advance: '15000.0000');
    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect(fn () => demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Cleared.'))
        ->toThrow(DomainException::class, $d['advance']->number);
});

it('clears the person once the advance is settled', function () {
    $d = demobilisable(advance: '15000.0000');
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    advances()->returnCash($d['advance'], '15000.0000', Carbon::parse('2026-05-19'), 'OR-88410');

    $clearance = demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Advance returned in full.');

    expect($clearance->cleared_at)->not->toBeNull();
});

it('refuses to clear the same person twice', function () {
    // Clearing is not editing — the same rule the punchlist turns on. The
    // second signature would overwrite the first.
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Cleared.');

    expect(fn () => demobilizations()->clear($demob->fresh(), $d['employee'], User::factory()->create(), 'Cleared again.'))
        ->toThrow(DomainException::class, 'already cleared');
});

it('refuses to clear somebody who never worked on the project', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    $stranger = contractedEmployeeIn($d['project']->organization()->sole(), 'EMP-NOBODY');

    expect(fn () => demobilizations()->clear($demob, $stranger, User::factory()->create(), 'Cleared.'))
        ->toThrow(DomainException::class, 'has no clearance line');
});

it('refuses a clearance with nothing said about what was returned', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect(fn () => demobilizations()->clear($demob, $d['employee'], User::factory()->create(), '  '))
        ->toThrow(DomainException::class, 'note');
});

it('refuses a cleared row with no clearer, at the database', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect(fn () => DemobilizationClearance::query()->whereKey($demob->clearances()->sole()->getKey())->update([
        'cleared_at' => now(),
        'cleared_by_user_id' => null,
        'note' => 'Cleared by nobody in particular.',
    ]))->toThrow(QueryException::class);
});

/*
|--------------------------------------------------------------------------
| What the registers say
|--------------------------------------------------------------------------
*/

it('reads plant still on site off the equipment register', function () {
    $d = demobilisable(equipment: true);

    expect(demobilizations()->plantStillOnSite($d['project'])->pluck('equipment_id')->all())
        ->toBe([$d['equipment']->getKey()]);
});

it('stops counting plant once it has been released', function () {
    $d = demobilisable(equipment: true);
    equipment()->release($d['assignment'], Carbon::parse('2026-05-19'), 'Returned to yard.');

    expect(demobilizations()->plantStillOnSite($d['project']))->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Completing it
|--------------------------------------------------------------------------
*/

it('refuses to complete while somebody is uncleared, and names them', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect(fn () => demobilizations()->complete($demob, User::factory()->create()))
        ->toThrow(DomainException::class, $d['employee']->employee_number);
});

it('refuses to complete while plant is still on site, and names it', function () {
    $d = demobilisable(equipment: true);
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Cleared.');

    expect(fn () => demobilizations()->complete($demob->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, $d['equipment']->code);
});

it('refuses to complete while an advance on the project is outstanding', function () {
    // Cleared people and returned plant are not the whole panel. Money advanced
    // against the site is an accountability of the site.
    $d = demobilisable(advance: '15000.0000');
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    advances()->returnCash($d['advance'], '15000.0000', Carbon::parse('2026-05-19'), 'OR-88410');
    demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Cleared.');

    advances()->release($d['employee'], $d['project'], '4000.0000', Carbon::parse('2026-05-19'), 'Site petty cash');

    expect(fn () => demobilizations()->complete($demob->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'unliquidated');
});

it('names every outstanding reason at once, not just the first', function () {
    $d = demobilisable(equipment: true, advance: '15000.0000');
    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect(count(demobilizations()->outstandingFor($demob)))->toBe(3);
});

it('completes when everybody is cleared and everything is back, and names who completed it', function () {
    $d = demobilisable(equipment: true);
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Cleared.');
    equipment()->release($d['assignment'], Carbon::parse('2026-05-19'), 'Returned to yard.');
    $ops = User::factory()->create();

    $completed = demobilizations()->complete($demob->fresh(), $ops, 'Site handed back to the client.');

    expect($completed->completed_at)->not->toBeNull()
        ->and((int) $completed->completed_by_user_id)->toBe($ops->getKey())
        ->and(demobilizations()->isComplete($completed))->toBeTrue();
});

it('refuses to complete a demobilization twice', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    demobilizations()->clear($demob, $d['employee'], User::factory()->create(), 'Cleared.');
    demobilizations()->complete($demob->fresh(), User::factory()->create());

    expect(fn () => demobilizations()->complete($demob->fresh(), User::factory()->create()))
        ->toThrow(DomainException::class, 'already complete');
});

it('refuses a completed row with no completer, at the database', function () {
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());

    expect(fn () => Demobilization::query()->whereKey($demob->getKey())->update([
        'completed_at' => now(),
        'completed_by_user_id' => null,
    ]))->toThrow(QueryException::class);
});

it('reports who cleared each person and when', function () {
    // Slide 9's close-out report, for the people panel.
    $d = demobilisable();
    $demob = demobilizations()->open($d['project'], User::factory()->create());
    $hr = User::factory()->create();
    demobilizations()->clear($demob, $d['employee'], $hr, 'Tools returned, ID surrendered.');

    $report = demobilizations()->clearanceReport($demob->fresh());

    expect($report)->toHaveCount(1)
        ->and($report[0]['employee'])->toBe($d['employee']->employee_number)
        ->and($report[0]['cleared_by'])->toBe($hr->name)
        ->and($report[0]['note'])->toBe('Tools returned, ID surrendered.');
});
