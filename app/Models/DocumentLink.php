<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One edge of the handoff spine.
 *
 * PLAN.md §1: every document carries the reference number of the document
 * before it. This table is that clause, made queryable in both directions.
 */
#[Fillable(['predecessor_type', 'predecessor_id', 'successor_type', 'successor_id'])]
class DocumentLink extends Model
{
    /**
     * @return MorphTo<Model, $this>
     */
    public function predecessor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function successor(): MorphTo
    {
        return $this->morphTo();
    }
}
