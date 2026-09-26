<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The counter row behind every document number.
 *
 * Read and written only by the numbering service — nothing else should touch
 * it, because a hand-edited counter reissues a number that is already printed
 * on a document.
 */
#[Fillable(['document_type', 'year', 'next_number'])]
class DocumentSequence extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
