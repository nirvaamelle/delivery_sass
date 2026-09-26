<?php

namespace App\Filament\Resources\Rfqs;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Rfqs\Pages\ListRfqs;
use App\Filament\Resources\Rfqs\Tables\RfqsTable;
use App\Models\Rfq;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Requests for quotation - who was invited to price the work.
 *
 * Read-only. Every document in this chain is created by a domain service that
 * runs the gates PLAN.md section 5 specifies, and decisions are taken in the
 * single approvals inbox rather than on seven document screens. A form here
 * would be a second way to write the row: the one that skips the checks.
 */
class RfqsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Rfq::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'RFQs';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    public static function table(Table $table): Table
    {
        return RfqsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRfqs::route('/'),
        ];
    }
}
