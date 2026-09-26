<?php

namespace App\Models;

use App\Domain\Hris\ManpowerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Slide 7's step 1 — the request that justifies an intake.
 *
 * @property ManpowerStatus $status
 * @property int $headcount
 * @property Carbon $required_by
 * @property ?Carbon $approved_at
 */
#[Fillable([
    'project_id', 'number', 'status', 'position', 'headcount', 'required_by',
    'justification', 'requested_by_user_id',
    'approved_at', 'approved_by_user_id', 'approval_remarks',
])]
class ManpowerRequisition extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ManpowerStatus::class,
            'headcount' => 'integer',
            'required_by' => 'date',
            'approved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
