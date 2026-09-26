<?php

namespace App\Filament\Resources\ApprovalMatrices\Pages;

use App\Domain\Approvals\ApprovalRouter;
use App\Domain\Approvals\OverlappingTierException;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateApprovalMatrix extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = ApprovalMatrixResource::class;

    /**
     * Write through the domain service, not through the model.
     *
     * PLAN.md §3's one architectural rule: Filament is the UI layer and nothing
     * else. If this page saved the record itself, the overlap guard would
     * protect the seeder and the test suite while the one path a human actually
     * uses walked straight past it.
     *
     * The only thing added here is translation — a domain refusal becomes a
     * form error on the field the user can act on. That is a UI concern, and it
     * is the sort of thing that belongs in the UI layer.
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(ApprovalRouter::class)->defineTier(
                documentType: $data['document_type'],
                tier: (int) $data['tier'],
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
