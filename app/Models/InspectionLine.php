<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $quantity_accepted
 * @property string $quantity_rejected
 */
#[Fillable(['inspection_id', 'receiving_report_line_id', 'quantity_accepted', 'quantity_rejected', 'rejection_reason'])]
class InspectionLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quantity_accepted' => 'decimal:4',
            'quantity_rejected' => 'decimal:4',
        ];
    }

    /**
     * @return BelongsTo<Inspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }

    /**
     * @return BelongsTo<ReceivingReportLine, $this>
     */
    public function receivingReportLine(): BelongsTo
    {
        return $this->belongsTo(ReceivingReportLine::class);
    }
}
