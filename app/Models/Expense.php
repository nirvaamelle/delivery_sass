<?php

namespace App\Models;

use App\Domain\Opex\ExpenseStatus;
use App\Domain\Posting\LedgerCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One site expense — slide 8's capture stage.
 *
 * @property ExpenseStatus $status
 * @property LedgerCategory $category
 * @property string $amount
 * @property Carbon $incurred_on
 * @property int $period_year
 * @property int $period_month
 * @property ?Carbon $returned_at
 * @property ?Carbon $posted_at
 */
#[Fillable([
    'project_id', 'cost_code_id', 'number', 'status', 'category', 'amount',
    'incurred_on', 'period_year', 'period_month',
    'receipt_reference', 'description', 'payee', 'captured_by_user_id',
    'return_reason', 'returned_at', 'returned_by_user_id',
    'posted_at', 'project_cost_ledger_entry_id',
])]
class Expense extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ExpenseStatus::class,
            'category' => LedgerCategory::class,
            'amount' => 'decimal:4',
            'incurred_on' => 'date',
            'period_year' => 'integer',
            'period_month' => 'integer',
            'returned_at' => 'datetime',
            'posted_at' => 'datetime',
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
     * @return BelongsTo<CostCode, $this>
     */
    public function costCode(): BelongsTo
    {
        return $this->belongsTo(CostCode::class);
    }
}
