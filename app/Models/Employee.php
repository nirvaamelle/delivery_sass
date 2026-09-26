<?php

namespace App\Models;

use App\Domain\Hris\EmploymentStatus;
use Database\Factories\EmployeeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The 201 file.
 *
 * Every personal identifier on this model is encrypted at rest — PLAN.md §3, in
 * the first migration that created them. The people these numbers belong to did
 * not choose to give them to a system; they gave them to an employer.
 *
 * @property EmploymentStatus $status
 * @property Carbon $date_hired
 * @property ?Carbon $separated_on
 * @property ?string $sss_number Decrypted by the cast; ciphertext in the column.
 * @property ?string $philhealth_number
 * @property ?string $pagibig_number
 * @property ?string $tin
 * @property ?string $bank_account_number
 * @property ?Carbon $assertion_date Not a column. The
 *                                   Gatekeeper's contract takes one subject, and the contract gate needs
 *                                   to know WHICH day it is being asked about — see HiringService.
 */
#[Fillable([
    'organization_id', 'employee_number', 'first_name', 'middle_name', 'last_name',
    'position', 'department', 'date_hired', 'status',
    'separated_on', 'separation_reason', 'separated_by_user_id',
    'sss_number', 'philhealth_number', 'pagibig_number', 'tin',
    'bank_account_number', 'address', 'contact_number', 'emergency_contact',
    'date_of_birth',
])]
class Employee extends Model
{
    /** @use HasFactory<EmployeeFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => EmploymentStatus::class,
            'date_hired' => 'date',
            'separated_on' => 'date',
            'date_of_birth' => 'date',

            // PLAN.md §3, one line per column so nothing is covered by accident
            // and nothing is missed by accident either.
            'sss_number' => 'encrypted',
            'philhealth_number' => 'encrypted',
            'pagibig_number' => 'encrypted',
            'tin' => 'encrypted',
            'bank_account_number' => 'encrypted',
            'address' => 'encrypted',
            'contact_number' => 'encrypted',
            'emergency_contact' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<PayrollLine, $this>
     */
    public function payrollLines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * Recurring allowances. Written only through AllowanceService.
     *
     * @return HasMany<EmployeeAllowance, $this>
     */
    public function allowances(): HasMany
    {
        return $this->hasMany(EmployeeAllowance::class);
    }

    /**
     * Service incentive leave. Written only through LeaveService.
     *
     * @return HasMany<LeaveRecord, $this>
     */
    public function leaveRecords(): HasMany
    {
        return $this->hasMany(LeaveRecord::class);
    }

    /**
     * @return HasMany<EmployeeRate, $this>
     */
    public function rates(): HasMany
    {
        return $this->hasMany(EmployeeRate::class);
    }

    /**
     * @return HasMany<EmploymentContract, $this>
     */
    public function employmentContracts(): HasMany
    {
        return $this->hasMany(EmploymentContract::class);
    }

    /**
     * The name a payslip prints.
     */
    public function fullName(): string
    {
        return trim(sprintf('%s %s', $this->first_name, $this->last_name));
    }
}
