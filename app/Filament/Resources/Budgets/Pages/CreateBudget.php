<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Domain\Budgets\BudgetService;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Budgets\BudgetsResource;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Draft a budget. The project is looked up through its scope, so a submitted id
 * for a project the user cannot see is not found rather than budgeted.
 */
class CreateBudget extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = BudgetsResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $project = Project::query()->find($data['project_id'] ?? null);

        if ($project === null) {
            throw ValidationException::withMessages(['data.project_id' => 'Choose a project you are assigned to.']);
        }

        $user = auth()->user();

        try {
            return app(BudgetService::class)->draft($project, (string) ($data['name'] ?? ''), $user instanceof User ? $user : null);
        } catch (InvalidBudgetDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.project_id' => $e->getMessage()]);
        }
    }

    protected function getRedirectUrl(): string
    {
        // Straight to the lines.
        return BudgetsResource::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
