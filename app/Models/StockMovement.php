<?php

namespace App\Models;

use App\Domain\Procurement\StockMovementType;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One signed change to a stock balance.
 *
 * Guarded like a ledger row: the balance is the sum of these, so an editable
 * movement is an editable balance with none of the audit trail that would
 * explain it. A miscount is corrected by a physical count, which posts its own
 * adjustment.
 *
 * @property string $quantity
 * @property ?string $unit_cost What the units were worth as they moved.
 * @property StockMovementType $type
 * @property Carbon $moved_at
 */
#[Fillable([
    'stock_card_id', 'type', 'quantity', 'unit_cost',
    'source_document_type', 'source_document_id',
    'moved_at', 'moved_by_user_id', 'remarks',
])]
class StockMovement extends Model
{
    protected static function booted(): void
    {
        static::updating(function (StockMovement $movement): void {
            throw new DomainException(
                'Stock movements are the record the balance is derived from and cannot be edited. Post a physical count to correct a discrepancy.'
            );
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StockMovementType::class,
            'unit_cost' => 'decimal:4',
            'quantity' => 'decimal:4',
            'moved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockCard, $this>
     */
    public function stockCard(): BelongsTo
    {
        return $this->belongsTo(StockCard::class);
    }
}
