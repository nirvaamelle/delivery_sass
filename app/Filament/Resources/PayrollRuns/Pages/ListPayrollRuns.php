<?php

namespace App\Filament\Resources\PayrollRuns\Pages;

use App\Domain\Hris\PayrollService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\PayrollRuns\PayrollRunsResource;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

/**
 * Opening a payroll run for one cutoff.
 *
 * **The period must match a configured cutoff exactly.** A run over arbitrary
 * dates would pay some days twice and none of them on the calendar the DTR
 * closing and release dates are computed from — so the service refuses it, and
 * the refusal names the cutoffs that would have been accepted.
 */
class ListPayrollRuns extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = PayrollRunsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openRun')
                ->label('Open a payroll run')
                ->icon('heroicon-o-calculator')
                ->modalDescription('The period has to be exactly one configured cutoff — 1–15 or 16 to the end of the month.')
                ->schema([
                    Select::make('organization_id')
                        ->label('Company')
                        ->required()
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Organization::query()->count() === 1
                            ? (int) Organization::query()->value('id')
                            : null),

                    DatePicker::make('period_start')->label('Period start')->required(),
                    DatePicker::make('period_end')->label('Period end')->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();

                    try {
                        app(PayrollService::class)->open(
                            Organization::query()->findOrFail($data['organization_id']),
                            Carbon::parse($data['period_start']),
                            Carbon::parse($data['period_end']),
                            $user instanceof User ? $user : null,
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title('The run was refused')->body($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Payroll run opened.')->send();
                }),
        ];
    }
}
