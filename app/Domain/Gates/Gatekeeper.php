<?php

namespace App\Domain\Gates;

/**
 * Declarative preconditions per document transition — PLAN.md §3.
 *
 * "No PO without an approved PR and a tabulated bid. No mobilization without a
 * countersigned PO. No billing without a verified statement of accomplishment."
 * Each of those is a list of named preconditions attached to one transition of
 * one document, declared once at boot and enforced everywhere the transition
 * can happen — a Filament action, a queued job, an importer.
 *
 * That last point is the reason this lives in app/Domain and not in a Filament
 * resource: PLAN.md §5 is explicit that a control which exists only in the UI
 * is not a control, because a queued job or a CSV import never opens a form.
 */
class Gatekeeper
{
    /**
     * Declared gates, keyed by document class and then transition.
     *
     * @var array<class-string, array<string, array<int, Precondition>>>
     */
    private array $gates = [];

    /**
     * Declare the preconditions for one transition of one document.
     *
     * An empty list is a legitimate and meaningful declaration: it states that
     * the transition is deliberately ungated.
     *
     * @param  class-string  $documentClass
     * @param  array<int, Precondition>  $preconditions
     */
    public function define(string $documentClass, string $transition, array $preconditions = []): void
    {
        $this->gates[$documentClass][$transition] = $preconditions;
    }

    /**
     * @param  class-string  $documentClass
     */
    public function isDeclared(string $documentClass, string $transition): bool
    {
        return isset($this->gates[$documentClass][$transition]);
    }

    /**
     * Every precondition this subject currently fails.
     *
     * All of them, deliberately. A user who fixes one blocker only to be shown
     * the next is how a one-day cycle-time target becomes a one-week one.
     *
     * @return array<string, string> precondition name => failure message
     *
     * @throws UndeclaredTransitionException when the transition has no declaration
     */
    public function failuresFor(object $subject, string $transition): array
    {
        $failures = [];

        foreach ($this->preconditionsFor($subject, $transition) as $precondition) {
            if (! $precondition->passes($subject)) {
                $failures[$precondition->name()] = $precondition->failureMessage($subject);
            }
        }

        return $failures;
    }

    /**
     * Refuse the transition unless every precondition passes.
     *
     * @throws UndeclaredTransitionException when the transition has no declaration
     * @throws GateFailedException when any precondition refuses
     */
    public function assert(object $subject, string $transition): void
    {
        $failures = $this->failuresFor($subject, $transition);

        if ($failures === []) {
            return;
        }

        throw new GateFailedException($failures, sprintf(
            '%s cannot %s: %s',
            class_basename($subject),
            $transition,
            implode(' ', $failures)
        ));
    }

    /**
     * @return array<int, Precondition>
     *
     * @throws UndeclaredTransitionException
     */
    private function preconditionsFor(object $subject, string $transition): array
    {
        $documentClass = $subject::class;

        if (! isset($this->gates[$documentClass][$transition])) {
            throw new UndeclaredTransitionException(sprintf(
                'No gate is declared for %s::%s. Declare it with an empty precondition list if it is deliberately ungated.',
                $documentClass,
                $transition
            ));
        }

        return $this->gates[$documentClass][$transition];
    }
}
