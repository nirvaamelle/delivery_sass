<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Domain\Contracts\ContractService;
use App\Domain\Contracts\InvalidContractDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Contracts\ContractsResource;
use App\Models\Project;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * Draft a contract through ContractService.
 *
 * The project is looked up through its scope, so a submitted id for a project
 * the user cannot see is not found rather than contracted.
 */
class CreateContract extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = ContractsResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $project = Project::query()->find($data['project_id'] ?? null);

        if ($project === null) {
            throw ValidationException::withMessages(['data.project_id' => 'Choose a project you are assigned to.']);
        }

        $user = auth()->user();

        try {
            return app(ContractService::class)->draft(
                $project,
                Arr::only($data, ['number', 'contract_sum', 'retention_rate', 'defects_liability_days', 'noa_date', 'ntp_date']),
                $user instanceof User ? $user : null,
            );
        } catch (InvalidContractDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the contract, where it is sent for signature.
        return ContractsResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
