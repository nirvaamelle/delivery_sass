<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\PurchaseOrders\Pages\ListPurchaseOrders;
use App\Filament\Resources\PurchaseOrders\Tables\PurchaseOrdersTable;
use App\Models\PurchaseOrder;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The award. Every document downstream hangs off this one.
 *
 * Read-only. Every document in this chain is created by a domain service that
 * runs the gates PLAN.md section 5 specifies, and decisions are taken in the
 * single approvals inbox rather than on seven document screens. A form here
 * would be a second way to write the row: the one that skips the checks.
 */
class PurchaseOrdersResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Purchase orders';

    protected static string|UnitEnum|null $navigationGroup = 'Procurement';

    protected static ?int $navigationSort = 30;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    public static function table(Table $table): Table
    {
        return PurchaseOrdersTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseOrders::route('/'),
        ];
    }
}
