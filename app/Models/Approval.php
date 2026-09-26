<?php

namespace App\Models;

use App\Domain\Approvals\ApprovalDecision;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One approval step against one document — the row the unified inbox lists.
 *
 * @property int $tier
 * @property int $step
 * @property ?Carbon $decided_at
 */
#[Fillable([
    'approvable_type',
    'approvable_id',
    'document_type',
    'tier',
    'step',
    'approver_role',
    'approver_user_id',
    'decision',
    'decided_at',
    'remarks',
    'sole_source',
])]
class Approval extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'step' => 'integer',
            'decision' => ApprovalDecision::class,
            'decided_at' => 'datetime',
            'sole_source' => 'boolean',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approver_user_id');
    }
}
