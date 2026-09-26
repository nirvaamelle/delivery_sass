<?php

namespace App\Filament\Resources\Accomplishments\Pages;

use App\Domain\Billing\AccomplishmentService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Accomplishments\AccomplishmentsResource;
use App\Models\Project;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

/**
 * Recording what was built this period — the measurement every billing rests on.
 *
 * A figure that goes DOWN needs a reason. A re-measurement is legitimate; a
 * percentage that quietly drops is the one nobody can account for at close-out,
 * and the service refuses it without one.
 */
class ListAccomplishments extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = AccomplishmentsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordAccomplishment')
                ->label('Record accomplishment')
                ->icon('heroicon-o-chart-bar')
                ->modalDescription('The percentage is work-to-date, not this period alone. A figure below the last one needs a reason.')
                ->schema([
                    Select::make('project_id')
                        ->label('Project')
                        ->searchable()
                        ->required()
                        // Scoped: only projects the user can see.
                        ->options(fn (): array => Project::query()->orderBy('code')->get()
                            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->code.' — '.$project->name])
                            ->all()),

                    DatePicker::make('period_start')->label('Period start')->required(),
                    DatePicker::make('period_end')->label('Period end')->required(),

                    TextInput::make('percentage_complete')
                        ->label('Complete (%)')
                        ->required()
                        ->rules(['numeric', 'gte:0', 'lte:100'])
                        ->helperText('Work to date, 0 to 100. Above 100 would bill more than the contract.'),

                    Textarea::make('remarks')
                        ->rows(2)
                        ->helperText('Required if this is lower than the last measurement.'),
                ])
                ->action(function (array $data, Action $action): void {
                    $project = Project::query()->find($data['project_id'] ?? null);

                    if ($project === null) {
                        Notification::make()->danger()->title('Choose a project you are assigned to.')->send();
                        $action->halt();

                        return;
                    }

                    $user = auth()->user();

                    try {
                        app(AccomplishmentService::class)->record(
                            $project,
                            Carbon::parse($data['period_start']),
                            Carbon::parse($data['period_end']),
                            (string) $data['percentage_complete'],
                            $user instanceof User ? $user : null,
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Accomplishment recorded.')->send();
                }),
        ];
    }
}
