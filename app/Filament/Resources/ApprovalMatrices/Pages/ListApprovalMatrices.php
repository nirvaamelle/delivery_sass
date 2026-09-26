<?php

namespace App\Filament\Resources\ApprovalMatrices\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListApprovalMatrices extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ApprovalMatrixResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
