<?php

namespace App\Filament\Resources\Users\Pages;

use App\Domain\Access\InvalidAccountDetail;
use App\Domain\Access\UserAccountService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Users\UsersResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Name, email and roles; "Set password" as its own act. No delete.
 */
class EditUser extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = UsersResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setPassword')
                ->label('Set password')
                ->icon('heroicon-o-key')
                ->modalDescription('Replaces the current password. The old one is not shown, and neither is the new one after saving.')
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable(false)
                        ->required()
                        ->minLength(UserAccountService::MIN_PASSWORD_LENGTH),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(UserAccountService::class)->setPassword($this->account(), (string) $data['password'], $this->actingUser());
                    } catch (InvalidAccountDetail $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Password set.')->send();
                }),
        ];
    }

    /**
     * Roles are a relation, not an attribute, so they are filled by name; the
     * password hash is removed so it never reaches the browser.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['password'], $data['remember_token'], $data['two_factor_secret'], $data['two_factor_last_code']);

        $data['roles'] = $this->account()->getRoleNames()->all();

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof User) {
            throw new LogicException('The account edit page was given something other than an account.');
        }

        $service = app(UserAccountService::class);

        try {
            return DB::transaction(function () use ($service, $record, $data): User {
                $service->updateDetails($record, (string) ($data['name'] ?? ''), (string) ($data['email'] ?? ''), $this->actingUser());

                return $service->setRoles($record, array_values($data['roles'] ?? []), $this->actingUser());
            });
        } catch (InvalidAccountDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }

    private function account(): User
    {
        $record = $this->getRecord();

        if (! $record instanceof User) {
            throw new LogicException('The account edit page has no account.');
        }

        return $record;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
