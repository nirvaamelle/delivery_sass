<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What a subcontractor's defect cost to put right — F6's missing table.
 *
 * The amount is the cost of the remedy, posted to the ledger when the charge is
 * raised. What the project will actually RECOVER is not a column here: it
 * depends on how much of the subcontract is still unpaid at settlement, so it is
 * derived by the service like every other balance in this build.
 *
 * @property string $amount DECIMAL(18,4), surfaced as a string.
 * @property Carbon $raised_at
 * @property ?Carbon $posted_at
 */
#[Fillable([
    'punchlist_item_id', 'subcontract_id', 'project_id', 'cost_code_id',
    'number', 'amount', 'description', 'raised_at', 'raised_by_user_id',
    'posted_at', 'project_cost_ledger_entry_id',
])]
class BackCharge extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:4',
            'raised_at' => 'datetime',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PunchlistItem, $this>
     */
    public function punchlistItem(): BelongsTo
    {
        return $this->belongsTo(PunchlistItem::class);
    }

    /**
     * @return BelongsTo<Subcontract, $this>
     */
    public function subcontract(): BelongsTo
    {
        return $this->belongsTo(Subcontract::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_user_id');
    }
}
