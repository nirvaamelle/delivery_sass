<?php

use App\Domain\Billing\AccomplishmentStatus;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| Accomplishments and joint surveys — P2-04
|--------------------------------------------------------------------------
|
| Slide 6's small print, and it is the rule the whole billing chain rests on:
| "every submission needs a joint accomplishment survey signed by the client
| representative before an invoice can be raised."
|
| **Joint means both signatures, and the client's is the one that binds.** A
| survey the contractor signed alone is a measurement, not an agreement — and a
| measurement the client never agreed to is exactly what a returned billing is
| made of. So verification is not a status somebody sets; it is a consequence of
| two signatures existing.
|
| The second rule here is subtler. Progress normally goes up, but a **returned**
| billing is re-measured and the figure can legitimately come DOWN. Refusing that
| outright would make the returned branch unusable; allowing it silently would
| let a figure drop with no record of why. So a decrease is allowed and requires
| a reason.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('records a measured accomplishment as unverified', function () {
    // Unverified is where every accomplishment starts. Anything else would make
    // the client's signature a formality applied to a decision already taken.
    $project = Project::factory()->create();

    $accomplishment = accomplishments()->record(
        $project,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
        '52.00',
        User::factory()->create(),
    );

    expect($accomplishment->number)->toStartWith('ACC-2026-')
        ->and($accomplishment->status)->toBe(AccomplishmentStatus::Measured)
        ->and((string) $accomplishment->percentage_complete)->toBe('52.00');
});

it('refuses an accomplishment above one hundred percent', function () {
    // 110% complete is not ahead of schedule, it is a measurement error — and
    // it would bill the client for more than the contract.
    $project = Project::factory()->create();

    expect(fn () => accomplishments()->record(
        $project,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
        '110.00',
        User::factory()->create(),
    ))->toThrow(DomainException::class);
});

it('refuses a period that ends before it starts', function () {
    $project = Project::factory()->create();

    expect(fn () => accomplishments()->record(
        $project,
        Carbon::parse('2026-05-31'),
        Carbon::parse('2026-05-01'),
        '52.00',
        User::factory()->create(),
    ))->toThrow(DomainException::class);
});

it('carries the previous percentage so the period is what is billed', function () {
    // A progress billing bills the DIFFERENCE, not the total. Without the
    // previous figure on the row, every billing would invoice the whole of the
    // work done to date.
    $project = Project::factory()->create();
    $user = User::factory()->create();

    accomplishments()->record($project, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), '35.00', $user);
    $second = accomplishments()->record($project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', $user);

    expect((string) $second->previous_percentage)->toBe('35.00')
        ->and(accomplishments()->periodMovement($second))->toBe('17.00');
});

it('refuses a decrease with no reason given', function () {
    // A figure that drops silently is the one nobody can explain at close-out.
    $project = Project::factory()->create();
    $user = User::factory()->create();

    accomplishments()->record($project, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), '52.00', $user);

    expect(fn () => accomplishments()->record($project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '48.00', $user))
        ->toThrow(DomainException::class);
});

it('allows a re-measured decrease when the reason is recorded', function () {
    // The returned branch depends on this: a billing sent back for
    // re-measurement produces a lower figure, and refusing it outright would
    // make the returned path unusable.
    $project = Project::factory()->create();
    $user = User::factory()->create();

    accomplishments()->record($project, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), '52.00', $user);

    $revised = accomplishments()->record(
        $project,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
        '48.00',
        $user,
        'Re-measured after client survey: pier 7 rebar not yet tied.',
    );

    expect((string) $revised->percentage_complete)->toBe('48.00')
        ->and($revised->remarks)->not->toBeNull();
});

it('does not verify an accomplishment the client has not signed', function () {
    // THE RULE. The contractor's own signature is a measurement; the client's is
    // the agreement, and only an agreement can be invoiced against.
    $project = Project::factory()->create();
    $accomplishment = accomplishments()->record(
        $project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', User::factory()->create()
    );

    $survey = accomplishments()->openSurvey($accomplishment, Carbon::parse('2026-06-01'), 'E. Cruz', 'M. Reyes');

    accomplishments()->signAsContractor($survey, User::factory()->create());

    expect($accomplishment->fresh()->status)->toBe(AccomplishmentStatus::Measured)
        ->and(accomplishments()->isVerified($accomplishment->fresh()))->toBeFalse();
});

it('verifies the accomplishment once both sides have signed', function () {
    $project = Project::factory()->create();
    $accomplishment = accomplishments()->record(
        $project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', User::factory()->create()
    );

    $survey = accomplishments()->openSurvey($accomplishment, Carbon::parse('2026-06-01'), 'E. Cruz', 'M. Reyes');

    accomplishments()->signAsContractor($survey, User::factory()->create());
    accomplishments()->signAsClient($survey->fresh(), 'E. Cruz');

    expect($accomplishment->fresh()->status)->toBe(AccomplishmentStatus::Verified)
        ->and(accomplishments()->isVerified($accomplishment->fresh()))->toBeTrue();
});

it('refuses a second joint survey on one accomplishment', function () {
    // Two surveys on one measurement is two client positions on the same work,
    // and whichever was signed last would win by accident.
    $project = Project::factory()->create();
    $accomplishment = accomplishments()->record(
        $project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', User::factory()->create()
    );

    accomplishments()->openSurvey($accomplishment, Carbon::parse('2026-06-01'), 'E. Cruz', 'M. Reyes');

    expect(fn () => accomplishments()->openSurvey($accomplishment->fresh(), Carbon::parse('2026-06-02'), 'E. Cruz', 'M. Reyes'))
        ->toThrow(QueryException::class);
});

it('refuses a client signature from somebody other than the named representative', function () {
    // The survey names who will sign for the client. Accepting any name would
    // make the representative field decorative, and the signature unattributable.
    $project = Project::factory()->create();
    $accomplishment = accomplishments()->record(
        $project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', User::factory()->create()
    );

    $survey = accomplishments()->openSurvey($accomplishment, Carbon::parse('2026-06-01'), 'E. Cruz', 'M. Reyes');

    expect(fn () => accomplishments()->signAsClient($survey, 'Somebody Else'))
        ->toThrow(DomainException::class);
});

it('reports the latest verified percentage for a project', function () {
    // The number the billing gate reads. Unverified measurements are invisible
    // to it — which is the whole point of "no billing without a verified
    // statement of accomplishment".
    $project = Project::factory()->create();
    $user = User::factory()->create();

    $first = accomplishments()->record($project, Carbon::parse('2026-04-01'), Carbon::parse('2026-04-30'), '35.00', $user);
    $survey = accomplishments()->openSurvey($first, Carbon::parse('2026-05-01'), 'E. Cruz', 'M. Reyes');
    accomplishments()->signAsContractor($survey, $user);
    accomplishments()->signAsClient($survey->fresh(), 'E. Cruz');

    // Measured but never surveyed: it must not move the verified figure.
    accomplishments()->record($project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00', $user);

    expect(accomplishments()->verifiedPercentageFor($project->fresh()))->toBe('35.00');
});

it('reports zero verified progress on a project with nothing signed', function () {
    // Absence is not permission. A project with no verified survey has verified
    // zero, so no progress milestone can bill against it.
    $project = Project::factory()->create();

    expect(accomplishments()->verifiedPercentageFor($project))->toBe('0.00');
});
