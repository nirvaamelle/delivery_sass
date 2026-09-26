<?php

namespace App\Models;

use App\Domain\Mobilization\PermitType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A permit, with its own expiry clock.
 *
 * Permits append rather than overwrite, like accreditations and bonds before
 * them: the question asked after an incident is whether the site was permitted
 * on the DAY, and overwriting keeps only the answer for today.
 *
 * @property PermitType $type
 * @property Carbon $issued_on
 * @property Carbon $expires_on
 */
#[Fillable([
    'project_id', 'type', 'number', 'issuing_authority',
    'issued_on', 'expires_on', 'remarks',
])]
class Permit extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PermitType::class,
            'issued_on' => 'date',
            'expires_on' => 'date',
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
