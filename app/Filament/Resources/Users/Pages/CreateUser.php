<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\InvalidAccountDetail;
use App\Domain\Access\UserAccountService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Users\UsersResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateUser extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = UsersResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $user = auth()->user();

        try {
            return app(UserAccountService::class)->create(
                (string) ($data['name'] ?? ''),
                (string) ($data['email'] ?? ''),
                (string) ($data['password'] ?? ''),
                array_values($data['roles'] ?? []),
                $user instanceof User ? $user : null,
            );
        } catch (InvalidAccountDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }
}
