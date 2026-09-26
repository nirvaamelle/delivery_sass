<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What must be on file before this milestone can be submitted — F4.
 *
 * @property bool $required
 */
#[Fillable(['billing_milestone_id', 'document_key', 'label', 'required'])]
class MilestoneDocumentRequirement extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required' => 'boolean'];
    }

    /**
     * @return BelongsTo<BillingMilestone, $this>
     */
    public function milestone(): BelongsTo
    {
        return $this->belongsTo(BillingMilestone::class, 'billing_milestone_id');
    }
}
