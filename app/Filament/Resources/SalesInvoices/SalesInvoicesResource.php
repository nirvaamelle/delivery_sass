<?php

namespace App\Filament\Resources\SalesInvoices;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Filament\Resources\SalesInvoices\Tables\SalesInvoicesTable;
use App\Models\SalesInvoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What the client was asked to pay, and what is still outstanding.
 *
 * Read-only, like every other document screen in this build. The document is
 * created by a service that runs the gates slide 6 specifies — the milestone's
 * document set, the verified accomplishment, the deducted lines still blocked —
 * and a form here would be the one way to write the row that skips all three.
 */
class SalesInvoicesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = SalesInvoice::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Sales invoices';

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    public static function table(Table $table): Table
    {
        return SalesInvoicesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSalesInvoices::route('/'),
        ];
    }
}
