<?php

namespace App\Models;

use App\Domain\Billing\AccomplishmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Work measured over a period — slide 6's statement of accomplishment.
 *
 * @property string $percentage_complete
 * @property string $previous_percentage
 * @property AccomplishmentStatus $status
 * @property Carbon $period_start
 * @property Carbon $period_end
 */
#[Fillable([
    'project_id', 'number', 'period_start', 'period_end',
    'percentage_complete', 'previous_percentage', 'status',
    'measured_by_user_id', 'remarks',
])]
class Accomplishment extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'percentage_complete' => 'decimal:2',
            'previous_percentage' => 'decimal:2',
            'status' => AccomplishmentStatus::class,
            'period_start' => 'date',
            'period_end' => 'date',
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
     * @return HasOne<JointSurvey, $this>
     */
    public function survey(): HasOne
    {
        return $this->hasOne(JointSurvey::class);
    }
}
