<?php

namespace App\Filament\Resources\PurchaseRequisitions;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\PurchaseRequisitions\Pages\ListPurchaseRequisitions;
use App\Filament\Resources\PurchaseRequisitions\Tables\PurchaseRequisitionsTable;
use App\Models\PurchaseRequisition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Purchase requisitions — the head of the chain.
 *
 * **Read-only, deliberately.** Decisions are made in the one inbox (P0-15), not
 * on seven document screens; a resource that could approve here would be the
 * second place an approval can happen, and the deck's whole complaint is that
 * approvals are scattered. What this screen is for is answering "where is
 * PR-2026-00042 and what is holding it up".
 */
class PurchaseRequisitionResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = PurchaseRequisition::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Requisitions';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function table(Table $table): Table
    {
        return PurchaseRequisitionsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        // A requisition is raised through RequisitionService, which runs the F14
        // project gate and the budget availability check. A Filament form that
        // wrote the row directly would walk past both.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequisitions::route('/'),
        ];
    }
}
