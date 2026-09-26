<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Domain\Projects\InvalidProjectDetail;
use App\Domain\Projects\ProjectService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Projects\ProjectsResource;
use App\Models\Organization;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Open a project through ProjectService, never by writing the row directly.
 */
class CreateProject extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = ProjectsResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $organization = Organization::query()->findOrFail($data['organization_id'] ?? null);
        unset($data['organization_id']);

        $user = auth()->user();

        try {
            return app(ProjectService::class)->open($organization, $data, $user instanceof User ? $user : null);
        } catch (InvalidProjectDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }
}
