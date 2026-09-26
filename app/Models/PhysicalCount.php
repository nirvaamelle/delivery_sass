<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * What was actually on the shelf, against what the card said.
 *
 * @property string $book_quantity
 * @property string $counted_quantity
 * @property string $variance
 * @property Carbon $counted_at
 */
#[Fillable([
    'stock_card_id', 'number', 'book_quantity', 'counted_quantity',
    'variance', 'counted_at', 'counted_by_user_id', 'remarks',
])]
class PhysicalCount extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'book_quantity' => 'decimal:4',
            'counted_quantity' => 'decimal:4',
            'variance' => 'decimal:4',
            'counted_at' => 'datetime',
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
