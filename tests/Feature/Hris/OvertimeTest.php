<?php

use App\Domain\Hris\OvertimeStatus;
use App\Domain\Hris\OvertimeType;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Overtime and night differential authorities — P3-06
|--------------------------------------------------------------------------
|
| Slide 7: "OT and night differential approved IN WRITING."
|
| The failure this prevents is ordinary rather than fraudulent. A biometric
| punch at 21:40 proves somebody was on site; it does not prove anybody asked
| them to be. Paying every hour past eight as overtime turns the time clock into
| the approval — and the premium is 25% on top, so the cost of nobody deciding
| is a quarter more than the cost of somebody deciding.
|
| So the rule has two halves, and both are tested:
|
|   - **worked but not authorised is not payable** as overtime. The hours are
|     not deleted — the DTR still says 11 — they are simply not paid at the
|     premium until somebody authorises them;
|   - **authorised but not worked is not payable either.** An authority for four
|     hours on a day somebody left at five is a permission, not a timesheet.
|
| Payable overtime is the SMALLER of the two, on every day, and that one line is
| most of this task.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-20 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

/**
 * A contracted employee with one imported day.
 */
function dayWorked(string $in, string $out, int $breakMinutes = 60, string $date = '2026-05-04'): array
{
    $employee = contractedEmployee('EMP-OT-'.uniqid());
    $project = Project::factory()->create();

    timekeeping()->import($project, [
        ['employee_number' => $employee->employee_number, 'work_date' => $date, 'time_in' => $in, 'time_out' => $out, 'break_minutes' => $breakMinutes],
    ]);

    $record = timekeeping()->dtrFor($employee->fresh(), Carbon::parse($date), Carbon::parse($date))->sole();

    return [$employee->fresh(), $project, $record];
}

it('requests an overtime authority as pending', function () {
    [$employee, $project] = dayWorked('07:00', '20:00');

    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '3.00', 'Deck pour ran past sunset.', User::factory()->create());

    expect($authority->status)->toBe(OvertimeStatus::Requested)
        ->and((string) $authority->hours_authorised)->toBe('3.00');
});

it('pays no overtime for hours worked without an authority', function () {
    // THE RULE, first half. 07:00–20:00 less an hour is twelve hours, four past
    // eight. Nobody asked for them, so none of the four is payable at the premium.
    [$employee, $project, $record] = dayWorked('07:00', '20:00');

    expect((string) $record->hours_worked)->toBe('12.00')
        ->and(overtime()->payableOvertimeHours($record))->toBe('0.00');
});

it('pays no overtime on an authority still awaiting approval', function () {
    // Requested is not approved. "In writing" means the approval, not the ask.
    [$employee, $project, $record] = dayWorked('07:00', '20:00');

    overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '4.00', 'Pour.', User::factory()->create());

    expect(overtime()->payableOvertimeHours($record->fresh()))->toBe('0.00');
});

it('pays approved overtime up to the hours actually worked past the standard day', function () {
    [$employee, $project, $record] = dayWorked('07:00', '20:00');

    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '4.00', 'Pour.', User::factory()->create());
    overtime()->approve($authority, User::factory()->create(), 'SE memo 2026-05-04-02');

    expect(overtime()->payableOvertimeHours($record->fresh()))->toBe('4.00');
});

it('caps payable overtime at the hours authorised', function () {
    // Worked four past eight, authorised for two. Two are payable; the other two
    // stay on the DTR, unpaid at the premium, until somebody authorises them.
    [$employee, $project, $record] = dayWorked('07:00', '20:00');

    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '2.00', 'Pour.', User::factory()->create());
    overtime()->approve($authority, User::factory()->create(), 'SE memo 2026-05-04-02');

    expect(overtime()->payableOvertimeHours($record->fresh()))->toBe('2.00')
        ->and((string) $record->fresh()->hours_worked)->toBe('12.00');
});

it('caps payable overtime at the hours actually worked', function () {
    // THE RULE, second half. Authorised for four, left at 17:00 — no hours past
    // eight at all. An authority is a permission, not a timesheet.
    [$employee, $project, $record] = dayWorked('07:00', '16:00');

    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '4.00', 'Planned pour, cancelled.', User::factory()->create());
    overtime()->approve($authority, User::factory()->create(), 'SE memo 2026-05-04-02');

    expect(overtime()->payableOvertimeHours($record->fresh()))->toBe('0.00');
});

it('refuses an approval with nothing in writing', function () {
    // "Approved in writing" — the written reference is the approval. Without it
    // the authority is a verbal okay recorded by whoever clicked the button.
    [$employee, $project] = dayWorked('07:00', '20:00');
    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '4.00', 'Pour.', User::factory()->create());

    expect(fn () => overtime()->approve($authority, User::factory()->create(), '   '))
        ->toThrow(DomainException::class);
});

it('refuses an approval by the person who requested it', function () {
    // A judgement call beyond the slide, recorded as one: an authority the
    // requester approves for themselves is a request with a second click. The
    // deck names the timekeeper and the site engineer as different owners.
    [$employee, $project] = dayWorked('07:00', '20:00');
    $requester = User::factory()->create();
    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '4.00', 'Pour.', $requester);

    expect(fn () => overtime()->approve($authority, $requester, 'Self-approved'))
        ->toThrow(DomainException::class);
});

it('refuses a second authority of the same type for one person on one day', function () {
    // Two authorities stack into a cap nobody decided on.
    [$employee, $project] = dayWorked('07:00', '20:00');
    overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '2.00', 'Pour.', User::factory()->create());

    expect(fn () => overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '2.00', 'More pour.', User::factory()->create()))
        ->toThrow(QueryException::class);
});

it('refuses an authority for zero hours or more than a day', function () {
    [$employee, $project] = dayWorked('07:00', '20:00');

    expect(fn () => overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '0.00', 'Nothing.', User::factory()->create()))
        ->toThrow(DomainException::class)
        ->and(fn () => overtime()->request($employee, $project, Carbon::parse('2026-05-05'), OvertimeType::Overtime, '25.00', 'Impossible.', User::factory()->create()))
        ->toThrow(DomainException::class);
});

it('computes night hours from the punches, not from the authority', function () {
    // 14:00–23:30 with no break crosses 22:00 by ninety minutes. The punches say
    // when the work happened; the authority only says it may be paid.
    [$employee, $project, $record] = dayWorked('14:00', '23:30', 0);

    expect(overtime()->nightHoursWorked($record))->toBe('1.50');
});

it('pays night differential only on approved authority, capped both ways', function () {
    [$employee, $project, $record] = dayWorked('14:00', '23:30', 0);

    expect(overtime()->payableNightHours($record))->toBe('0.00');

    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::NightDifferential, '1.00', 'Curing watch.', User::factory()->create());
    overtime()->approve($authority, User::factory()->create(), 'SE memo 2026-05-04-03');

    // Worked 1.5 night hours, authorised 1.0.
    expect(overtime()->payableNightHours($record->fresh()))->toBe('1.00');
});

it('pays nothing for an authority that was rejected', function () {
    [$employee, $project, $record] = dayWorked('07:00', '20:00');
    $authority = overtime()->request($employee, $project, Carbon::parse('2026-05-04'), OvertimeType::Overtime, '4.00', 'Pour.', User::factory()->create());

    overtime()->reject($authority, User::factory()->create(), 'Pour was scheduled for day shift.');

    expect($authority->fresh()->status)->toBe(OvertimeStatus::Rejected)
        ->and(overtime()->payableOvertimeHours($record->fresh()))->toBe('0.00');
});
