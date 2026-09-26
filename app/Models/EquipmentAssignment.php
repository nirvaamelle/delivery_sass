<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Where a machine was, and between which dates.
 *
 * Assignments append rather than overwrite. "Which project was the excavator on
 * in May" is the question fuel costs are attributed by, and a single mutable
 * `current_project_id` answers it only for today.
 *
 * @property Carbon $assigned_on
 * @property ?Carbon $released_on
 */
#[Fillable([
    'equipment_id', 'project_id', 'assigned_on', 'released_on',
    'assigned_by_user_id', 'released_by_user_id', 'remarks',
])]
class EquipmentAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assigned_on' => 'date',
            'released_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Equipment, $this>
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
