<?php

namespace App\Filament\Resources\Employees\Pages;

use App\Domain\Hris\EmployeeService;
use App\Domain\Hris\EmploymentStatus;
use App\Domain\Hris\InvalidEmployeeDetail;
use App\Domain\Hris\PayBasis;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Employees\EmployeesResource;
use App\Models\Employee;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Edit a 201 file — details through updateDetails(), and two acts of their own.
 *
 * **No delete.** Payroll lines, DTRs and contracts point at this row, and
 * leaving is recorded by separation, never by removal.
 *
 * **"Set new rate"** appends a dated entry. The earlier rates stay, so payroll
 * already computed for past periods reads the rate that was in force then.
 *
 * **"Separate employee"** requires how the employment ended and why. It is only
 * offered while the employee is active.
 */
class EditEmployee extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = EmployeesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('setRate')
                ->label('Set new rate')
                ->icon('heroicon-o-banknotes')
                ->modalHeading('Set a new pay rate')
                ->modalDescription('Adds a dated entry to the rate history. Earlier rates are kept, so payroll already computed for past periods does not change. The rate itself is never shown on screen.')
                ->schema([
                    Select::make('basis')
                        ->label('Pay basis')
                        ->options(collect(PayBasis::cases())->mapWithKeys(fn (PayBasis $basis): array => [$basis->value => ucfirst($basis->value)])->all())
                        ->required(),

                    TextInput::make('rate')
                        ->label('Rate')
                        ->required()
                        ->rules(['numeric', 'gt:0']),

                    DatePicker::make('effective_from')
                        ->label('Effective from')
                        ->required()
                        ->default(now()),

                    Textarea::make('remarks'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(EmployeeService::class)->setRate(
                            $this->employee(),
                            PayBasis::from((string) $data['basis']),
                            bcadd((string) $data['rate'], '0', 4),
                            Carbon::parse($data['effective_from']),
                            $this->actingUser(),
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        );
                    } catch (QueryException) {
                        // The unique index on (employee, effective_from).
                        Notification::make()->danger()->title('A rate already starts on that date.')->body('Choose another effective date.')->send();
                        $action->halt();
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('New rate recorded.')->send();
                }),

            Action::make('separate')
                ->label('Separate employee')
                ->icon('heroicon-o-arrow-right-start-on-rectangle')
                ->color('danger')
                ->visible(fn (): bool => $this->employee()->status === EmploymentStatus::Active)
                ->modalHeading('Separate this employee')
                ->modalDescription('Records how and why the employment ended. The 201 file is kept.')
                ->schema([
                    Select::make('status')
                        ->label('How it ended')
                        ->options(collect(EmploymentStatus::cases())
                            ->filter(fn (EmploymentStatus $status): bool => $status->hasSeparated())
                            ->mapWithKeys(fn (EmploymentStatus $status): array => [$status->value => ucfirst(str_replace('_', ' ', $status->value))])
                            ->all())
                        ->required(),

                    DatePicker::make('separated_on')->label('Last day')->required()->default(now()),

                    Textarea::make('reason')->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(EmployeeService::class)->separate(
                            $this->employee(),
                            EmploymentStatus::from((string) $data['status']),
                            Carbon::parse($data['separated_on']),
                            (string) $data['reason'],
                            $this->actingUser(),
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }

                    $this->employee()->refresh();
                    Notification::make()->success()->title('Employee separated.')->send();
                }),
        ];
    }

    /**
     * Keep the decrypted identifiers out of the form state entirely.
     *
     * Filament fills an edit form from every non-hidden attribute and sends that
     * state to the browser through Livewire — its own source says so and says to
     * unset sensitive attributes here. The write-only fields also clear their
     * state after hydrating, but that makes secrecy depend on the order hooks
     * run in. Removing the values before the form ever sees them does not.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        foreach (['sss_number', 'philhealth_number', 'pagibig_number', 'tin', 'bank_account_number'] as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Employee) {
            throw new LogicException('The employee edit page was given something other than an employee.');
        }

        try {
            return app(EmployeeService::class)->updateDetails($record, $data, $this->actingUser());
        } catch (InvalidEmployeeDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        }
    }

    private function employee(): Employee
    {
        $record = $this->getRecord();

        if (! $record instanceof Employee) {
            throw new LogicException('The employee edit page has no employee.');
        }

        return $record;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
