<?php

namespace App\Models;

use App\Domain\Projects\ProjectRole;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One person's place on one project.
 *
 * @property ProjectRole $role
 * @property Carbon $assigned_on
 */
#[Fillable(['project_id', 'user_id', 'role', 'assigned_on', 'assigned_by_user_id'])]
class ProjectAssignment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ProjectRole::class,
            'assigned_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
