<?php

namespace App\Domain\Hris;

use App\Domain\Support\Money;
use App\Models\Employee;
use App\Models\EmployeeRate;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Collection;

/**
 * The 201 file — P3-01.
 *
 * Two rules carry this service, and both are about time rather than storage.
 *
 * **A rate is a history, not a column.** Payroll never asks what somebody earns;
 * it asks what they were earning during the period being computed. A mutable
 * `daily_rate` answers only for today, which means a raise granted in May
 * silently restates April's payroll the moment anybody re-runs or corrects it —
 * and late corrections are exactly when this matters. So rates append, each with
 * the date it takes effect, and `rateOn()` is the only way payroll reads one.
 *
 * **Absence is not zero.** An employee with no rate in force on a date has no
 * rate, and `rateOn()` returns null rather than '0.0000'. A payroll run that
 * treated a missing rate as zero would pay somebody nothing and look like it had
 * worked, which is the worst of both outcomes.
 *
 * Everything sensitive is encrypted by the model's casts, declared in the
 * migration that created the columns — PLAN.md §3. Nothing in this service has
 * to remember to encrypt, which is the point of putting it there.
 */
class EmployeeService
{
    /** What a payroll run cannot do without. */
    private const REQUIRED_AT_HIRE = ['employee_number', 'first_name', 'last_name', 'date_hired'];

    /**
     * What HR may correct on an existing 201 file.
     *
     * An allow-list, not "everything fillable". The employee number is what
     * timekeeping imports and payroll lines match on; the date hired is what the
     * contract gate reads; the organization decides whose payroll a person is
     * on; status and separation have their own act with a required reason.
     */
    private const EDITABLE = [
        'first_name', 'middle_name', 'last_name', 'position', 'department',
        'date_of_birth', 'address', 'contact_number', 'emergency_contact',
        'sss_number', 'philhealth_number', 'pagibig_number', 'tin', 'bank_account_number',
    ];

    /**
     * Never displayed on screen, so a blank submission means "unchanged" rather
     * than "erase it".
     */
    private const WRITE_ONLY = ['sss_number', 'philhealth_number', 'pagibig_number', 'tin', 'bank_account_number'];

    /** Blank is refused for these on update, as it is at hire. */
    private const NOT_BLANK = ['first_name', 'last_name'];

    /**
     * Open a 201 file.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidEmployeeDetail when a required field is missing, the
     *                               employee number is taken, or a government
     *                               number is malformed
     */
    public function hire(Organization $organization, array $attributes): Employee
    {
        foreach (self::REQUIRED_AT_HIRE as $field) {
            if (! array_key_exists($field, $attributes) || $this->isBlank($attributes[$field])) {
                throw new InvalidEmployeeDetail($field, sprintf('A new hire needs %s — a payroll run cannot pay somebody without it.', $field));
            }
        }

        $number = trim((string) $attributes['employee_number']);

        // Checked here for a readable message. The unique index still refuses a
        // duplicate from anything that bypasses this service.
        if (Employee::query()->where('employee_number', $number)->exists()) {
            throw new InvalidEmployeeDetail('employee_number', sprintf(
                'Employee number %s is already in use. Timekeeping imports match on it, so two people cannot share one.',
                $number,
            ));
        }

        GovernmentIds::assertValid($attributes);

        return Employee::query()->create(array_merge($attributes, [
            'employee_number' => $number,
            'organization_id' => $organization->getKey(),
            'status' => EmploymentStatus::Active,
        ]));
    }

    /**
     * Correct the details on a 201 file.
     *
     * Allowed for somebody who has left, too: final pay, certificates of
     * employment and disputes all read a leaver's file.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidEmployeeDetail when a field is not editable, a name is
     *                               blank, or a government number is malformed
     */
    public function updateDetails(Employee $employee, array $attributes, ?User $by = null): Employee
    {
        $locked = array_values(array_diff(array_keys($attributes), self::EDITABLE));

        if ($locked !== []) {
            throw new InvalidEmployeeDetail($locked[0], sprintf(
                'Not a detail that can be corrected here: %s. The employee number, date hired and company are fixed at hire; status changes through separation, and pay through a new rate.',
                implode(', ', $locked),
            ));
        }

        foreach (self::WRITE_ONLY as $field) {
            if (array_key_exists($field, $attributes) && $this->isBlank($attributes[$field])) {
                unset($attributes[$field]);
            }
        }

        foreach (self::NOT_BLANK as $field) {
            if (array_key_exists($field, $attributes) && $this->isBlank($attributes[$field])) {
                throw new InvalidEmployeeDetail($field, sprintf('%s cannot be blank.', $field));
            }
        }

        GovernmentIds::assertValid($attributes);

        foreach ($attributes as $field => $value) {
            if (is_string($value)) {
                $trimmed = trim($value);
                $attributes[$field] = $trimmed === '' ? null : $trimmed;
            }
        }

        if ($attributes === []) {
            return $employee;
        }

        $employee->update($attributes);

        // Which fields changed, never their values: several are encrypted
        // personal identifiers, and the activity log is exported to auditors.
        activity()
            ->performedOn($employee)
            ->causedBy($by)
            ->withProperties(['fields' => array_keys($attributes)])
            ->log('employee-details-updated');

        return $employee->refresh();
    }

    /**
     * Record a rate, effective from a date.
     *
     * @throws DomainException when the rate is not a positive decimal
     */
    public function setRate(
        Employee $employee,
        PayBasis $basis,
        string $rate,
        CarbonInterface $effectiveFrom,
        ?User $by = null,
        ?string $remarks = null,
    ): EmployeeRate {
        if (bccomp($rate, '0.0000', Money::SCALE) <= 0) {
            throw new DomainException(sprintf(
                'A rate of %s is not a wage. Employee %s cannot be paid nothing by configuration.',
                $rate,
                $employee->employee_number,
            ));
        }

        return EmployeeRate::query()->create([
            'employee_id' => $employee->getKey(),
            'basis' => $basis,
            'rate' => $rate,
            'effective_from' => $effectiveFrom,
            'set_by_user_id' => $by?->getKey(),
            'remarks' => $remarks,
        ]);
    }

    /**
     * The rate in force on a given day.
     *
     * Inclusive of its own first day: somebody hired or promoted on the first of
     * the month is on the new rate that day, and being wrong here underpays them
     * for one shift.
     *
     * Returns null when no rate had taken effect yet — see the class docblock.
     */
    public function rateOn(Employee $employee, CarbonInterface $date): ?EmployeeRate
    {
        return $employee->rates()
            ->whereDate('effective_from', '<=', $date)
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * End employment, with the reason and the kind of ending.
     *
     * @throws DomainException when already separated, the status is not a
     *                         separation, or no reason is given
     */
    public function separate(
        Employee $employee,
        EmploymentStatus $status,
        CarbonInterface $separatedOn,
        string $reason,
        ?User $by = null,
    ): Employee {
        if ($employee->status->hasSeparated()) {
            throw new DomainException(sprintf(
                'Employee %s already left on %s as %s.',
                $employee->employee_number,
                $employee->separated_on?->toDateString() ?? 'an unrecorded date',
                $employee->status->value,
            ));
        }

        if (! $status->hasSeparated()) {
            throw new DomainException('A separation must name how the employment ended, not that it continues.');
        }

        if (trim($reason) === '') {
            // The reason is what final pay, re-hireability and any later dispute
            // all rest on. "Inactive" with no explanation answers none of them.
            throw new DomainException(sprintf(
                'Separating employee %s needs a reason.',
                $employee->employee_number,
            ));
        }

        $employee->update([
            'status' => $status,
            'separated_on' => $separatedOn,
            'separation_reason' => $reason,
            'separated_by_user_id' => $by?->getKey(),
        ]);

        return $employee->refresh();
    }

    private function isBlank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /**
     * Who a payroll run should iterate.
     *
     * @return Collection<int, Employee>
     */
    public function activeFor(Organization $organization): Collection
    {
        return Employee::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', EmploymentStatus::Active)
            ->orderBy('employee_number')
            ->get();
    }

    /**
     * Was this person employed on a given date?
     *
     * Read from the dates rather than the status, because the status is about
     * today and a payroll run for April is not about today. Somebody who left in
     * May was employed for the whole of April and is owed for it.
     */
    public function wasEmployedOn(Employee $employee, CarbonInterface $date): bool
    {
        if ($date->lt($employee->date_hired)) {
            return false;
        }

        return $employee->separated_on === null || ! $date->gt($employee->separated_on);
    }
}
