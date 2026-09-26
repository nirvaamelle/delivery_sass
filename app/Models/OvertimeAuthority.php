<?php

namespace App\Models;

use App\Domain\Hris\OvertimeStatus;
use App\Domain\Hris\OvertimeType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A written permission to pay a premium — slide 7's "approved in writing".
 *
 * @property OvertimeType $type
 * @property OvertimeStatus $status
 * @property string $hours_authorised
 * @property Carbon $work_date
 * @property Carbon $requested_at
 * @property ?Carbon $decided_at
 */
#[Fillable([
    'employee_id', 'project_id', 'work_date', 'type', 'hours_authorised',
    'reason', 'status', 'requested_at', 'requested_by_user_id',
    'written_reference', 'decided_at', 'decided_by_user_id', 'decision_remarks',
])]
class OvertimeAuthority extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => OvertimeType::class,
            'status' => OvertimeStatus::class,
            'hours_authorised' => 'decimal:2',
            'work_date' => 'date',
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
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
}
