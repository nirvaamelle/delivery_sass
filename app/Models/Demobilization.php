<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Slide 9 step 5 — releasing the site, its people and its plant.
 *
 * @property Carbon $opened_at
 * @property ?Carbon $completed_at
 */
#[Fillable([
    'project_id', 'number', 'opened_at', 'opened_by_user_id',
    'completed_at', 'completed_by_user_id', 'remarks',
])]
class Demobilization extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return HasMany<DemobilizationClearance, $this>
     */
    public function clearances(): HasMany
    {
        return $this->hasMany(DemobilizationClearance::class)->orderBy('id');
    }
}
