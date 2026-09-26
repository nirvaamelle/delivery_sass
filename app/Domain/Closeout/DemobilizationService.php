<?php

namespace App\Domain\Closeout;

use App\Domain\Numbering\DocumentNumberGenerator;
use App\Domain\Opex\CashAdvanceService;
use App\Domain\Support\Money;
use App\Models\CashAdvance;
use App\Models\DailyTimeRecord;
use App\Models\Demobilization;
use App\Models\DemobilizationClearance;
use App\Models\Employee;
use App\Models\EquipmentAssignment;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Slide 9 step 5 — demobilization and clearance.
 *
 * Its panel on the slide is "People and assets": clearance and final pay,
 * equipment and IT asset return. (The scorecards listed beside them are P5-09.)
 *
 * **A person is a named act; assets and money are derived.** Each individual
 * gets a row somebody signs, because slide 9's rule is a named clearer per line
 * and final pay is the line that matters most to the person on it. Plant still
 * on site is a question the equipment register already answers, and an
 * unliquidated advance is one P4-02's register answers — so neither is tickable
 * here. A demobilization that let somebody assert "all plant returned" while
 * assignments sat open would be a softer copy of a fact that already exists,
 * and the soft copy is the one filled in on the last day of a job.
 *
 * **The cross-chain rule:** a person holding an unliquidated advance cannot be
 * cleared for final pay. Final pay is the last moment the company has any
 * leverage to recover it — F17's day-26 sweep charges the next payroll, and for
 * somebody leaving the site there may not be one.
 */
class DemobilizationService
{
    public function __construct(
        private readonly DocumentNumberGenerator $numbering,
        private readonly TurnoverService $turnovers,
        private readonly CashAdvanceService $advances,
    ) {}

    /**
     * Open the demobilization, with a clearance line per person who worked.
     *
     * @throws DomainException when turnover has not been accepted, or the
     *                         project is already being demobilized
     */
    public function open(Project $project, User $by, ?CarbonInterface $openedAt = null): Demobilization
    {
        $pack = $this->turnovers->forProject($project);

        if ($pack === null || ! $this->turnovers->isAccepted($pack)) {
            // Slide 9's order. Releasing the site before the client has
            // accepted it leaves nobody there to answer for what they find.
            throw new DomainException(sprintf(
                'Project %s has not been accepted by the client. Turnover comes before demobilization, and a site released early has nobody left on it to answer for what the client finds.',
                $project->code,
            ));
        }

        if (Demobilization::query()->where('project_id', $project->getKey())->exists()) {
            throw new DomainException(sprintf(
                'Project %s is already being demobilized. Two demobilizations are two answers to whether the site has been released.',
                $project->code,
            ));
        }

        return DB::transaction(function () use ($project, $by, $openedAt): Demobilization {
            $demobilization = Demobilization::query()->create([
                'project_id' => $project->getKey(),
                'number' => $this->numbering->next('DEMOB'),
                'opened_at' => $openedAt ?? now(),
                'opened_by_user_id' => $by->getKey(),
            ]);

            foreach ($this->workedOn($project) as $employee) {
                DemobilizationClearance::query()->create([
                    'demobilization_id' => $demobilization->getKey(),
                    'employee_id' => $employee->getKey(),
                ]);
            }

            return $demobilization->refresh();
        });
    }

    /**
     * Clear one person for final pay.
     *
     * @throws DomainException when the demobilization is complete, the person
     *                         has no line on it, is already cleared, holds an
     *                         unliquidated advance, or the note is blank
     */
    public function clear(
        Demobilization $demobilization,
        Employee $employee,
        User $by,
        string $note,
    ): DemobilizationClearance {
        if ($demobilization->completed_at !== null) {
            throw new DomainException(sprintf(
                'Demobilization %s is already complete. A clearance signed afterwards was not part of what was checked.',
                $demobilization->number,
            ));
        }

        $clearance = $demobilization->clearances()->where('employee_id', $employee->getKey())->first();

        if ($clearance === null) {
            // Only people who worked on the site are on the list, and adding
            // somebody at clearance time would clear them of accountabilities
            // nobody looked for.
            throw new DomainException(sprintf(
                'Employee %s has no clearance line on demobilization %s — no time was logged for them on this project.',
                $employee->employee_number,
                $demobilization->number,
            ));
        }

        if ($clearance->cleared_at !== null) {
            // Clearing is not editing. The second signature overwrites the
            // first, and the report names the wrong person.
            throw new DomainException(sprintf(
                'Employee %s was already cleared on %s.',
                $employee->employee_number,
                $clearance->cleared_at->toDateString(),
            ));
        }

        if (trim($note) === '') {
            throw new DomainException(
                'A clearance needs a note saying what was returned and what was checked. A signature against nothing is a tick.'
            );
        }

        $outstanding = $this->advancesHeldBy($employee, $demobilization->project()->sole());

        if ($outstanding->isNotEmpty()) {
            throw new DomainException(sprintf(
                'Employee %s still holds %s. Final pay is the last moment the company can recover an advance, and a leaver has no next payroll for the day-26 sweep to charge.',
                $employee->employee_number,
                $outstanding->map(fn (CashAdvance $advance): string => sprintf(
                    '%s (%s outstanding)',
                    $advance->number,
                    $this->advances->outstandingFor($advance),
                ))->implode(', '),
            ));
        }

        $clearance->update([
            'cleared_at' => now(),
            'cleared_by_user_id' => $by->getKey(),
            'note' => trim($note),
        ]);

        return $clearance->refresh();
    }

    /**
     * Plant the register still shows on this site — slide 9's "returned and
     * logged", read from the register rather than from a tick.
     *
     * @return Collection<int, EquipmentAssignment>
     */
    public function plantStillOnSite(Project $project): Collection
    {
        return EquipmentAssignment::query()
            ->with('equipment')
            ->where('project_id', $project->getKey())
            ->whereNull('released_on')
            ->orderBy('assigned_on')
            ->get();
    }

    /**
     * Advances against this project with money still unaccounted for.
     *
     * @return Collection<int, CashAdvance>
     */
    public function unliquidatedAdvances(Project $project): Collection
    {
        return CashAdvance::query()
            ->where('project_id', $project->getKey())
            ->orderBy('released_on')
            ->get()
            ->filter(fn (CashAdvance $advance): bool => ! Money::isZero($this->advances->outstandingFor($advance)))
            ->values();
    }

    /**
     * Every reason the site cannot be released — all of them at once.
     *
     * @return array<int, string>
     */
    public function outstandingFor(Demobilization $demobilization): array
    {
        $project = $demobilization->project()->sole();
        $outstanding = [];

        foreach ($demobilization->clearances()->with('employee')->whereNull('cleared_at')->get() as $clearance) {
            $employee = $clearance->employee()->sole();

            $outstanding[] = sprintf(
                '%s (%s %s) has not been cleared for final pay',
                $employee->employee_number,
                $employee->first_name,
                $employee->last_name,
            );
        }

        foreach ($this->plantStillOnSite($project) as $assignment) {
            $outstanding[] = sprintf(
                'Equipment %s is still on site, assigned since %s',
                $assignment->equipment()->sole()->code,
                $assignment->assigned_on->toDateString(),
            );
        }

        foreach ($this->unliquidatedAdvances($project) as $advance) {
            $outstanding[] = sprintf(
                'Cash advance %s is unliquidated, %s outstanding',
                $advance->number,
                $this->advances->outstandingFor($advance),
            );
        }

        return $outstanding;
    }

    /**
     * Release the site.
     *
     * @throws DomainException when it is already complete, or anything is
     *                         outstanding
     */
    public function complete(Demobilization $demobilization, User $by, ?string $remarks = null): Demobilization
    {
        if ($demobilization->completed_at !== null) {
            throw new DomainException(sprintf(
                'Demobilization %s is already complete, signed on %s.',
                $demobilization->number,
                $demobilization->completed_at->toDateString(),
            ));
        }

        $outstanding = $this->outstandingFor($demobilization);

        if ($outstanding !== []) {
            throw new DomainException(sprintf(
                'Demobilization %s cannot be completed. Outstanding: %s.',
                $demobilization->number,
                implode('; ', $outstanding),
            ));
        }

        $demobilization->update([
            'completed_at' => now(),
            'completed_by_user_id' => $by->getKey(),
            'remarks' => $remarks === null || trim($remarks) === '' ? null : trim($remarks),
        ]);

        return $demobilization->refresh();
    }

    public function isComplete(Demobilization $demobilization): bool
    {
        return $demobilization->completed_at !== null;
    }

    public function forProject(Project $project): ?Demobilization
    {
        return Demobilization::query()->where('project_id', $project->getKey())->first();
    }

    /**
     * Who cleared each person and when — the people half of slide 9's
     * close-out report.
     *
     * @return array<int, array<string, mixed>>
     */
    public function clearanceReport(Demobilization $demobilization): array
    {
        return $demobilization->clearances()->with(['employee', 'clearedBy'])->get()
            ->map(fn (DemobilizationClearance $clearance): array => [
                'employee' => $clearance->employee()->sole()->employee_number,
                'name' => trim($clearance->employee()->sole()->first_name.' '.$clearance->employee()->sole()->last_name),
                'cleared_at' => $clearance->cleared_at?->toDateTimeString(),
                'cleared_by' => $clearance->clearedBy?->name,
                'note' => $clearance->note,
            ])
            ->all();
    }

    /**
     * Everybody with time logged against the project.
     *
     * Any daily time record, validated or not. Somebody whose last day was
     * never certified still has tools and an ID card, and leaving them off the
     * list is how a clearance list quietly shortens itself.
     *
     * @return Collection<int, Employee>
     */
    private function workedOn(Project $project): Collection
    {
        return Employee::query()
            ->whereIn('id', DailyTimeRecord::query()
                ->where('project_id', $project->getKey())
                ->select('employee_id'))
            ->orderBy('employee_number')
            ->get();
    }

    /**
     * @return Collection<int, CashAdvance>
     */
    private function advancesHeldBy(Employee $employee, Project $project): Collection
    {
        return $this->unliquidatedAdvances($project)
            ->filter(fn (CashAdvance $advance): bool => (int) $advance->employee_id === $employee->getKey())
            ->values();
    }
}
