<?php

namespace App\Models;

use App\Domain\Hris\DtrStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One day, and what the site said about it.
 *
 * @property DtrStatus $status
 * @property string $hours_worked
 * @property Carbon $work_date
 * @property ?Carbon $validated_at
 * @property ?Carbon $held_from_period_end
 * @property ?Carbon $paid_in_period_end
 */
#[Fillable([
    'employee_id', 'project_id', 'timelog_id', 'work_date', 'hours_worked',
    'status', 'validated_at', 'validated_by_user_id', 'remarks',
    'held_from_period_end', 'paid_in_period_end',
])]
class DailyTimeRecord extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DtrStatus::class,
            'hours_worked' => 'decimal:2',
            'work_date' => 'date',
            'validated_at' => 'datetime',
            'held_from_period_end' => 'date',
            'paid_in_period_end' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Timelog, $this>
     */
    public function timelog(): BelongsTo
    {
        return $this->belongsTo(Timelog::class);
    }

    /**
     * Was this day carried over from an earlier cutoff?
     *
     * What a payslip needs in order to say "carried from 1-15 May" rather than
     * showing an unexplained extra day.
     */
    public function wasHeld(): bool
    {
        return $this->held_from_period_end !== null;
    }
}
