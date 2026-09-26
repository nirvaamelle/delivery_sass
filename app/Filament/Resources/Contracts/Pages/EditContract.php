<?php

namespace App\Filament\Resources\Contracts\Pages;

use App\Domain\Contracts\ContractService;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Contracts\InvalidContractDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Contracts\ContractsResource;
use App\Models\Contract;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A contract's acts: send for signature, sign, return to draft, terminate.
 *
 * **Signing builds the billing schedule in the same transaction**, so a signed
 * contract is always billable. If the contract type has no milestones the whole
 * signing is refused rather than leaving a signed contract nobody can bill.
 *
 * No delete: requisitions, billings and the ledger all point back here.
 */
class EditContract extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = ContractsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sendForSignature')
                ->label('Send for signature')
                ->icon('heroicon-o-paper-airplane')
                ->visible(fn (): bool => $this->contract()->status === ContractStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('The terms are fixed once it goes out. Bring it back to draft to change them.')
                ->action(fn (Action $action) => $this->attempt(
                    $action,
                    'Sent for signature.',
                    fn () => app(ContractService::class)->sendForSignature($this->contract(), $this->actingUser()),
                )),

            Action::make('returnToDraft')
                ->label('Return to draft')
                ->icon('heroicon-o-arrow-uturn-left')
                ->color('warning')
                ->visible(fn (): bool => $this->contract()->status === ContractStatus::ForSignature)
                ->schema([Textarea::make('reason')->required()->rows(2)])
                ->action(fn (array $data, Action $action) => $this->attempt(
                    $action,
                    'Returned to draft.',
                    fn () => app(ContractService::class)->returnToDraft($this->contract(), (string) $data['reason'], $this->actingUser()),
                )),

            Action::make('signContract')
                ->label('Sign')
                ->icon('heroicon-o-pencil-square')
                ->color('success')
                ->visible(fn (): bool => $this->contract()->status === ContractStatus::ForSignature)
                ->modalDescription('Signing builds the billing schedule from the contract type, so the project can bill from day one.')
                ->schema([
                    DatePicker::make('signed_at')->label('Date signed')->required()->default(now()),

                    Select::make('contract_type')
                        ->label('Billing schedule')
                        ->required()
                        ->default('default')
                        // PLACEHOLDER: Part D item 2 — the deck's five milestones
                        // are the only configured type.
                        ->options(fn (): array => collect(array_keys((array) config('billing.contract_types', [])))
                            ->mapWithKeys(fn (string $type): array => [$type => ucfirst(str_replace('_', ' ', $type))])
                            ->all()),
                ])
                ->action(fn (array $data, Action $action) => $this->attempt(
                    $action,
                    'Contract signed, and its billing schedule built.',
                    fn () => app(ContractService::class)->sign(
                        $this->contract(),
                        Carbon::parse($data['signed_at']),
                        (string) $data['contract_type'],
                        $this->actingUser(),
                    ),
                )),

            Action::make('terminateContract')
                ->label('Terminate')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn (): bool => $this->contract()->status !== ContractStatus::Terminated)
                ->schema([Textarea::make('reason')->required()->rows(2)])
                ->action(fn (array $data, Action $action) => $this->attempt(
                    $action,
                    'Contract terminated.',
                    fn () => app(ContractService::class)->terminate($this->contract(), (string) $data['reason'], $this->actingUser()),
                )),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Contract) {
            throw new LogicException('The contract edit page was given something other than a contract.');
        }

        try {
            return app(ContractService::class)->updateDetails(
                $record,
                Arr::only($data, ['number', 'contract_sum', 'retention_rate', 'defects_liability_days', 'noa_date', 'ntp_date']),
                $this->actingUser(),
            );
        } catch (InvalidContractDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.number' => $e->getMessage()]);
        }
    }

    private function attempt(Action $action, string $success, callable $act): void
    {
        try {
            $act();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $action->halt();
        }

        $this->contract()->refresh();
        Notification::make()->success()->title($success)->send();
    }

    private function contract(): Contract
    {
        $record = $this->getRecord();

        if (! $record instanceof Contract) {
            throw new LogicException('The contract edit page has no contract.');
        }

        return $record;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
