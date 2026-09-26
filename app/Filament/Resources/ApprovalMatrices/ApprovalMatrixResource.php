<?php

namespace App\Filament\Resources\ApprovalMatrices;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\ApprovalMatrices\Pages\CreateApprovalMatrix;
use App\Filament\Resources\ApprovalMatrices\Pages\EditApprovalMatrix;
use App\Filament\Resources\ApprovalMatrices\Pages\ListApprovalMatrices;
use App\Filament\Resources\ApprovalMatrices\Schemas\ApprovalMatrixForm;
use App\Filament\Resources\ApprovalMatrices\Tables\ApprovalMatricesTable;
use App\Models\ApprovalMatrix;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class ApprovalMatrixResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = ApprovalMatrix::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return ApprovalMatrixForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ApprovalMatricesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovalMatrices::route('/'),
            'create' => CreateApprovalMatrix::route('/create'),
            'edit' => EditApprovalMatrix::route('/{record}/edit'),
        ];
    }
}
