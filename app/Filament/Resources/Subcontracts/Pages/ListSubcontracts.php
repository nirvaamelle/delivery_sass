<?php

namespace App\Filament\Resources\Subcontracts\Pages;

use App\Domain\Procurement\SubcontractService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Subcontracts\SubcontractsResource;
use App\Models\Project;
use App\Models\Vendor;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Awarding works to a subcontractor.
 *
 * The service re-checks accreditation and the performance bond at award, not
 * merely at accreditation: time passes between qualifying a subcontractor and
 * letting works to them, and certificates and bonds expire in it.
 */
class ListSubcontracts extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = SubcontractsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('awardSubcontract')
                ->label('Award a subcontract')
                ->icon('heroicon-o-plus')
                ->modalWidth('2xl')
                ->modalDescription('Accreditation and the performance bond are re-checked here, because both can have lapsed since the subcontractor qualified.')
                ->schema([
                    Select::make('project_id')
                        ->label('Project')
                        ->searchable()
                        ->required()
                        // Scoped: only projects the user can see.
                        ->options(fn (): array => Project::query()->orderBy('code')->get()
                            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->code.' — '.$project->name])
                            ->all()),

                    Select::make('vendor_id')
                        ->label('Subcontractor')
                        ->searchable()
                        ->required()
                        ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all()),

                    Textarea::make('scope_of_work')
                        ->label('Scope of works')
                        ->required()
                        ->rows(3),

                    TextInput::make('contract_amount')
                        ->label('Contract amount')
                        ->required()
                        ->rules(['numeric', 'gt:0'])
                        ->helperText('What back-charges are netted against, and what the final account resolves.'),

                    DatePicker::make('works_start')->label('Works start')->required(),
                    DatePicker::make('works_end')->label('Works end')->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $project = Project::query()->find($data['project_id'] ?? null);

                    if ($project === null) {
                        Notification::make()->danger()->title('Choose a project you are assigned to.')->send();
                        $action->halt();

                        return;
                    }

                    try {
                        app(SubcontractService::class)->award(
                            $project,
                            Vendor::query()->findOrFail($data['vendor_id']),
                            (string) $data['scope_of_work'],
                            (string) $data['contract_amount'],
                            Carbon::parse($data['works_start']),
                            Carbon::parse($data['works_end']),
                        );
                    } catch (DomainException|InvalidArgumentException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Subcontract awarded.')->send();
                }),
        ];
    }
}
