<?php

namespace App\Models;

use App\Domain\Billing\AgingBucket;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An unpaid invoice, escalated to a named role — F13.
 *
 * @property int $days_outstanding
 * @property string $amount_outstanding
 * @property AgingBucket $bucket
 * @property Carbon $escalated_at
 * @property ?Carbon $acknowledged_at
 */
#[Fillable([
    'sales_invoice_id', 'project_id', 'addressed_to_role',
    'days_outstanding', 'amount_outstanding', 'bucket', 'escalated_at',
    'acknowledged_at', 'acknowledged_by_user_id', 'resolution',
])]
class ArEscalation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'days_outstanding' => 'integer',
            'amount_outstanding' => 'decimal:4',
            'bucket' => AgingBucket::class,
            'escalated_at' => 'datetime',
            'acknowledged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
