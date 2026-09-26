<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One tier of the authority matrix for one document type.
 *
 * @property int $tier
 * @property string $min_amount
 * @property ?string $max_amount
 * @property array<int, string> $approver_roles
 * @property array<int, string> $required_documents
 */
#[Fillable([
    'document_type',
    'tier',
    'min_amount',
    'max_amount',
    'approver_roles',
    'required_documents',
])]
class ApprovalMatrix extends Model
{
    protected $table = 'approval_matrix';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tier' => 'integer',
            'min_amount' => 'decimal:4',
            'max_amount' => 'decimal:4',
            'approver_roles' => 'array',
            'required_documents' => 'array',
        ];
    }
}
