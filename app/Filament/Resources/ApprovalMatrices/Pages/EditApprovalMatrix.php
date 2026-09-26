<?php

namespace App\Filament\Resources\ApprovalMatrices\Pages;

use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\OverlappingTierException;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use App\Models\ApprovalMatrix;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class EditApprovalMatrix extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = ApprovalMatrixResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * As with creation, the write goes through the domain service so the
     * overlap check runs on the path a human uses.
     *
     * document_type and tier are disabled on this form, so the row being
     * redefined is always the row being edited — which is also why the values
     * come from the record rather than from the submitted data.
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ApprovalMatrix $record */
        try {
            return app(ApprovalRouter::class)->defineTier(
                documentType: $record->document_type,
                tier: $record->tier,
                minAmount: $data['min_amount'],
                maxAmount: $data['max_amount'] ?: null,
                approverRoles: $data['approver_roles'] ?? [],
                requiredDocuments: $data['required_documents'] ?? [],
            );
        } catch (OverlappingTierException $exception) {
            throw ValidationException::withMessages([
                'data.min_amount' => $exception->getMessage(),
            ]);
        }
    }
}
