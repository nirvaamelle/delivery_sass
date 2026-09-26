<?php

namespace App\Models;

use App\Domain\Billing\InvoiceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * What the client is asked to pay.
 *
 * @property string $gross_amount DECIMAL(18,4), surfaced as a string by the cast.
 * @property string $retention_amount
 * @property string $collectible_amount
 * @property ?string $withholding_code The F9 columns come from a schema macro,
 *                                     so they are declared rather than inferred.
 * @property ?string $withholding_rate
 * @property string $withholding_amount
 * @property ?string $withholding_certificate_reference
 * @property InvoiceStatus $status
 * @property Carbon $issued_on
 */
#[Fillable([
    'billing_id', 'project_id', 'number', 'status', 'issued_on',
    'gross_amount', 'retention_amount', 'collectible_amount',
    'withholding_code', 'withholding_rate', 'withholding_amount',
    'withholding_certificate_reference', 'issued_by_user_id', 'remarks',
    'posted_at', 'project_cost_ledger_entry_id',
])]
class SalesInvoice extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issued_on' => 'date',
            'gross_amount' => 'decimal:4',
            'retention_amount' => 'decimal:4',
            'collectible_amount' => 'decimal:4',
            'withholding_rate' => 'decimal:6',
            'withholding_amount' => 'decimal:4',
            'posted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Billing, $this>
     */
    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<OfficialReceipt, $this>
     */
    public function receipts(): HasMany
    {
        return $this->hasMany(OfficialReceipt::class);
    }
}
