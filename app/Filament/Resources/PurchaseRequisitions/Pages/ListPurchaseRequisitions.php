<?php

namespace App\Filament\Resources\PurchaseRequisitions\Pages;

use App\Domain\Requisitions\RequisitionService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\PurchaseRequisitions\PurchaseRequisitionResource;
use App\Models\CostCode;
use App\Models\Project;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Raising a requisition — the head of the procurement chain.
 *
 * Two gates stand behind this button and both refuse loudly: F14 wants a signed
 * contract and an opened budget on the project, and the budget check wants
 * enough left against the cost code. Neither is checked here — they are the
 * service's, so an import or a queued job meets the same refusal.
 *
 * **Every line needs a cost code.** A requisition without one cannot be checked
 * against a budget at all, which is exactly how overspend enters a project.
 */
class ListPurchaseRequisitions extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = PurchaseRequisitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raiseRequisition')
                ->label('Raise a requisition')
                ->icon('heroicon-o-clipboard-document-list')
                ->modalWidth('3xl')
                ->modalDescription('Refused unless the project has a signed contract and an open budget, and unless the cost code still has room.')
                ->schema([
                    Select::make('project_id')
                        ->label('Project')
                        ->searchable()
                        ->required()
                        ->live()
                        // Scoped: only projects the user can see.
                        ->options(fn (): array => Project::query()->orderBy('code')->get()
                            ->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->code.' — '.$project->name])
                            ->all()),

                    Repeater::make('lines')
                        ->label('Lines')
                        ->required()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Add a line')
                        ->schema([
                            Select::make('cost_code_id')
                                ->label('Cost code')
                                ->searchable()
                                ->required()
                                ->options(fn (): array => CostCode::query()
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->code.' — '.$code->name])
                                    ->all()),

                            TextInput::make('description')->required()->maxLength(255),
                            TextInput::make('amount')->required()->rules(['numeric', 'gt:0']),
                        ])
                        ->columns(3),
                ])
                ->action(function (array $data, Action $action): void {
                    $project = Project::query()->find($data['project_id'] ?? null);

                    if ($project === null) {
                        Notification::make()->danger()->title('Choose a project you are assigned to.')->send();
                        $action->halt();

                        return;
                    }

                    $lines = array_map(fn (array $line): array => [
                        'cost_code_id' => (int) $line['cost_code_id'],
                        'description' => (string) $line['description'],
                        'amount' => (string) $line['amount'],
                    ], $data['lines'] ?? []);

                    try {
                        app(RequisitionService::class)->raise($project, $lines);
                    } catch (DomainException $e) {
                        // A gate failure says which gate and why; it is the whole
                        // value of the refusal, so it is shown in full.
                        Notification::make()->danger()->title('The requisition was refused')->body($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Requisition raised as a draft.')->send();
                }),
        ];
    }
}
