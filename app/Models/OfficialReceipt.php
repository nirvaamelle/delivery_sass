<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Money actually received, and the certificate that substantiates what was
 * withheld from it.
 *
 * @property string $amount_received
 * @property Carbon $received_on
 */
#[Fillable([
    'sales_invoice_id', 'number', 'amount_received', 'received_on',
    'payment_reference', 'withholding_certificate_reference',
    'received_by_user_id', 'remarks',
])]
class OfficialReceipt extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_received' => 'decimal:4',
            'received_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}
