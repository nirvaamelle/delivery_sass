<?php

namespace App\Domain\Equipment;

use App\Domain\Support\Money;
use App\Models\CostCode;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentCost;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The equipment register — F5.
 *
 * PHASE-PLAN.md's finding is that fuel, repair and depreciation are named inputs
 * to every monthly consolidation, and PLAN.md §4 has no register at all, so the
 * input is unbuildable. What makes it buildable is not the table; it is the rule
 * about where a cost lands.
 *
 * **A machine is on one project at a time.** Assignments overlap in exactly one
 * situation — somebody forgot to release the last one — and if that is allowed,
 * every hour of fuel the machine burns can be charged to either project, and the
 * cost per unit on both becomes whatever was picked that morning. So an
 * assignment against an unreleased one is refused rather than merged.
 *
 * The consequence is the cost rule: an equipment cost does not name its project,
 * it INHERITS it from the assignment live on the date the cost was incurred. A
 * cost with no such assignment is refused rather than assigned to a default,
 * because the default in practice is whichever project happened to be open in
 * the form.
 *
 * Assignments append. "Which project was the excavator on in May" is the
 * question fuel is attributed by, and a mutable `current_project_id` answers it
 * only for today.
 */
class EquipmentService
{
    /**
     * Send a machine to a project.
     *
     * @throws DomainException when the machine has left the fleet or is still
     *                         assigned elsewhere
     */
    public function assign(
        Equipment $item,
        Project $project,
        CarbonInterface $assignedOn,
        ?User $by = null,
        ?string $remarks = null,
    ): EquipmentAssignment {
        if ($item->status->isTerminal()) {
            throw new DomainException(sprintf(
                'Equipment %s is %s. A machine that has left the fleet cannot be sent to a site.',
                $item->code,
                $item->status->value,
            ));
        }

        $open = $this->openAssignmentFor($item);

        if ($open !== null) {
            throw new DomainException(sprintf(
                'Equipment %s is still assigned to project %d since %s. Release it first — a machine on two projects at once makes every cost on it attributable to either.',
                $item->code,
                $open->project_id,
                $open->assigned_on->toDateString(),
            ));
        }

        return DB::transaction(function () use ($item, $project, $assignedOn, $by, $remarks): EquipmentAssignment {
            $assignment = EquipmentAssignment::query()->create([
                'equipment_id' => $item->getKey(),
                'project_id' => $project->getKey(),
                'assigned_on' => $assignedOn,
                'assigned_by_user_id' => $by?->getKey(),
                'remarks' => $remarks,
            ]);

            $item->update(['status' => EquipmentStatus::Deployed]);

            return $assignment->refresh();
        });
    }

    /**
     * Demobilize — slide 9's "returned and logged".
     *
     * @throws DomainException when already released, or released before it began
     */
    public function release(
        EquipmentAssignment $assignment,
        CarbonInterface $releasedOn,
        ?string $remarks = null,
        ?User $by = null,
    ): EquipmentAssignment {
        if ($assignment->released_on !== null) {
            throw new DomainException(sprintf(
                'That assignment was already released on %s.',
                $assignment->released_on->toDateString(),
            ));
        }

        if ($releasedOn->lt($assignment->assigned_on)) {
            throw new DomainException(sprintf(
                'Released %s but assigned %s. A machine cannot leave a site before it arrived, and the dates are what every cost between them is attributed by.',
                $releasedOn->toDateString(),
                $assignment->assigned_on->toDateString(),
            ));
        }

        return DB::transaction(function () use ($assignment, $releasedOn, $remarks, $by): EquipmentAssignment {
            $assignment->update([
                'released_on' => $releasedOn,
                'released_by_user_id' => $by?->getKey(),
                'remarks' => $remarks ?? $assignment->remarks,
            ]);

            $item = $assignment->equipment()->sole();

            if (! $item->status->isTerminal()) {
                $item->update(['status' => EquipmentStatus::Available]);
            }

            return $assignment->refresh();
        });
    }

    /**
     * Record fuel, a repair or a mobilization against the machine.
     *
     * The project is not a parameter. It is read from the assignment covering
     * the date, which is the only thing that makes the cost attributable at all.
     *
     * @throws DomainException when no assignment covers the date
     */
    public function recordCost(
        Equipment $item,
        EquipmentCostType $type,
        string $amount,
        CarbonInterface $incurredOn,
        CostCode $costCode,
        ?string $reference = null,
        ?User $by = null,
        ?string $remarks = null,
    ): EquipmentCost {
        if (Money::isZero($amount)) {
            throw new DomainException('A zero-amount equipment cost is noise on a register that exists to be read.');
        }

        $assignment = $this->assignmentCovering($item, $incurredOn);

        if ($assignment === null) {
            throw new DomainException(sprintf(
                'Equipment %s was on no project on %s. A cost with no assignment behind it has no project to carry it, and picking one is how a yard bill lands on whichever site was open in the form.',
                $item->code,
                $incurredOn->toDateString(),
            ));
        }

        return EquipmentCost::query()->create([
            'equipment_id' => $item->getKey(),
            'project_id' => $assignment->project_id,
            'cost_code_id' => $costCode->getKey(),
            'equipment_assignment_id' => $assignment->getKey(),
            'type' => $type,
            'amount' => $amount,
            'incurred_on' => $incurredOn,
            'reference' => $reference,
            'remarks' => $remarks,
            'recorded_by_user_id' => $by?->getKey(),
        ]);
    }

    /**
     * The assignment a machine is currently on, if any.
     */
    public function openAssignmentFor(Equipment $item): ?EquipmentAssignment
    {
        return $item->assignments()->whereNull('released_on')->first();
    }

    /**
     * The assignment live on a given date.
     *
     * An open assignment covers every date from its start onwards; a released
     * one covers up to and including the day it was released, because a machine
     * demobilized on the 20th did burn fuel on the 20th.
     */
    public function assignmentCovering(Equipment $item, CarbonInterface $date): ?EquipmentAssignment
    {
        return $item->assignments()
            ->whereDate('assigned_on', '<=', $date)
            ->where(function ($query) use ($date): void {
                $query->whereNull('released_on')
                    ->orWhereDate('released_on', '>=', $date);
            })
            ->orderByDesc('assigned_on')
            ->first();
    }

    /**
     * What a machine has cost a project, by cost type or in total.
     */
    public function costsFor(Equipment $item, ?EquipmentCostType $type = null): string
    {
        $query = $item->costs();

        if ($type !== null) {
            $query->where('type', $type);
        }

        $total = '0.0000';

        foreach ($query->pluck('amount') as $amount) {
            $total = Money::sum($total, (string) $amount);
        }

        return $total;
    }
}
