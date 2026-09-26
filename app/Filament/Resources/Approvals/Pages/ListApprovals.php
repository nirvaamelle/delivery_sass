<?php

namespace App\Filament\Resources\Approvals\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Approvals\ApprovalResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The inbox itself.
 *
 * No create action: an approval is opened by the Approvals service when a
 * document is submitted and closed by a decision. It is never something a
 * person types into existence.
 */
class ListApprovals extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ApprovalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
