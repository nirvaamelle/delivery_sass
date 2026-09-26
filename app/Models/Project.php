<?php

namespace App\Models;

use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectStatus;
use App\Domain\Projects\ScopedToProject;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Larastan infers a cast column as `string` unless the property is declared, so
 * without these two lines every `$project->phase === ProjectPhase::…` in the
 * build reads as a comparison that can never be true.
 *
 * @property ProjectPhase $phase
 * @property ProjectStatus $status
 */
#[Fillable(['organization_id', 'code', 'name', 'client_name', 'phase', 'status'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use ScopedToProject;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'phase' => ProjectPhase::class,
            'status' => ProjectStatus::class,
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
     * @return HasMany<Budget, $this>
     */
    public function budgets(): HasMany
    {
        return $this->hasMany(Budget::class);
    }

    /**
     * @return HasMany<Contract, $this>
     */
    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
