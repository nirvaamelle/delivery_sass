<?php

use App\Domain\Gates\GateFailedException;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Gates\Precondition;
use App\Domain\Gates\UndeclaredTransitionException;
use App\Models\Budget;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Gates — P0-10
|--------------------------------------------------------------------------
|
| The second cross-cutting service from PLAN.md §3: declarative preconditions
| per document, enforced service-side on every transition. "No PO without an
| approved PR and a tabulated bid. No billing without a verified statement of
| accomplishment."
|
| Phase 0 has no documents to gate yet — P0-11 registers the first real gate
| (F14) and Phase 1 registers the procurement chain's. What is built and proven
| here is the mechanism, and two decisions in it are deliberate:
|
|   - Every failing precondition is reported, not just the first. A clerk who
|     fixes one blocker only to be shown the next one is why the deck's
|     cycle-time targets get missed.
|   - A transition that was never declared is REFUSED, not waved through. An
|     ungated transition must be declared ungated, so a typo in a transition
|     name fails loudly instead of silently enforcing nothing.
|
*/

function gatekeeper(): Gatekeeper
{
    return app(Gatekeeper::class);
}

/**
 * A precondition that answers however it was told to.
 */
function precondition(string $name, bool $passes, string $message = 'blocked'): Precondition
{
    return new class($name, $passes, $message) implements Precondition
    {
        public function __construct(
            private string $name,
            private bool $passes,
            private string $message,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function passes(object $subject): bool
        {
            return $this->passes;
        }

        public function failureMessage(object $subject): string
        {
            return $this->message;
        }
    };
}

it('allows a transition when every precondition passes', function () {
    gatekeeper()->define(Budget::class, 'open', [
        precondition('has-lines', true),
        precondition('project-has-contract', true),
    ]);

    $budget = Budget::factory()->create();

    gatekeeper()->assert($budget, 'open');

    expect(gatekeeper()->failuresFor($budget, 'open'))->toBeEmpty();
});

it('blocks a transition and names the precondition that failed', function () {
    gatekeeper()->define(Budget::class, 'open', [
        precondition('has-lines', false, 'The budget has no lines.'),
    ]);

    $budget = Budget::factory()->create();

    expect(fn () => gatekeeper()->assert($budget, 'open'))
        ->toThrow(GateFailedException::class, 'The budget has no lines.');
});

it('reports every failing precondition, not only the first', function () {
    // Fixing one blocker at a time, with a round trip to discover each, is how
    // a one-day PR target becomes a one-week one.
    gatekeeper()->define(Budget::class, 'open', [
        precondition('has-lines', false, 'The budget has no lines.'),
        precondition('within-authority', false, 'Above the approver authority.'),
        precondition('project-active', true),
    ]);

    $budget = Budget::factory()->create();

    $failures = gatekeeper()->failuresFor($budget, 'open');

    expect($failures)->toHaveCount(2)
        ->and(array_keys($failures))->toBe(['has-lines', 'within-authority'])
        ->and($failures['within-authority'])->toBe('Above the approver authority.');
});

it('refuses a transition that was never declared', function () {
    // The gap this closes: a typo in a transition name would otherwise enforce
    // nothing at all, silently, for as long as nobody noticed.
    $budget = Budget::factory()->create();

    expect(fn () => gatekeeper()->assert($budget, 'aprove'))
        ->toThrow(UndeclaredTransitionException::class);
});

it('allows a transition that was declared with no preconditions', function () {
    // Ungated is a position, and it has to be stated rather than assumed.
    gatekeeper()->define(Budget::class, 'archive', []);

    $budget = Budget::factory()->create();

    gatekeeper()->assert($budget, 'archive');

    expect(gatekeeper()->isDeclared(Budget::class, 'archive'))->toBeTrue();
});

it('hands the subject to the precondition so it can inspect it', function () {
    gatekeeper()->define(Budget::class, 'open', [
        new class implements Precondition
        {
            public function name(): string
            {
                return 'named-original-budget';
            }

            public function passes(object $subject): bool
            {
                return $subject instanceof Budget && $subject->name === 'Original budget';
            }

            public function failureMessage(object $subject): string
            {
                return 'Only the original budget may be opened this way.';
            }
        },
    ]);

    $original = Budget::factory()->create(['name' => 'Original budget']);
    $revision = Budget::factory()->create(['name' => 'Revision 1']);

    gatekeeper()->assert($original, 'open');

    expect(fn () => gatekeeper()->assert($revision, 'open'))
        ->toThrow(GateFailedException::class);
});

it('keeps gates separate per document type', function () {
    // The same transition name on two documents means two different things.
    gatekeeper()->define(Budget::class, 'open', []);

    $project = Project::factory()->create();

    expect(fn () => gatekeeper()->assert($project, 'open'))
        ->toThrow(UndeclaredTransitionException::class);
});
