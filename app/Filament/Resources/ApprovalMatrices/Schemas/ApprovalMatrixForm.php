<?php

namespace App\Filament\Resources\ApprovalMatrices\Schemas;

use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * The authority matrix, as a form.
 *
 * PLACEHOLDER: Part D item 1 — the amounts entered here are exactly what the
 * deck leaves blank on purpose. This screen exists so the client can put the
 * real peso limits in without a deployment, which is the whole reason slide 12
 * calls every threshold a default to be overwritten.
 */
class ApprovalMatrixForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('document_type')
                    ->required()
                    ->maxLength(64)
                    ->helperText('The document this tier governs, e.g. purchase_order.')
                    // Which document and which tier a band belongs to is the
                    // matrix's identity. Changing it on an existing row is
                    // redefining the matrix rather than editing it.
                    ->disabledOn('edit'),

                TextInput::make('tier')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(255)
                    ->disabledOn('edit'),

                TextInput::make('min_amount')
                    ->label('Band floor')
                    ->required()
                    ->rule('regex:/^\d+(\.\d{1,4})?$/')
                    ->helperText('Inclusive. Decimal, up to four places.'),

                TextInput::make('max_amount')
                    ->label('Band ceiling')
                    ->rule('regex:/^\d+(\.\d{1,4})?$/')
                    ->helperText('Inclusive. Leave blank for the open-ended top tier.'),

                TagsInput::make('approver_roles')
                    ->required()
                    // A tier with nobody on it routes a document into a void:
                    // the approval sits pending and no inbox ever shows it.
                    ->rules(['array', 'min:1'])
                    ->helperText('In signing order. Tier 1 should keep a single approver — PLAN.md §9 names approval fatigue there as a risk.'),

                TagsInput::make('required_documents')
                    ->helperText('What this tier will not consider a request without.'),
            ]);
    }
}
