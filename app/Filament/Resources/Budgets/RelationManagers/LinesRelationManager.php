<?php

namespace App\Filament\Resources\Budgets\RelationManagers;

use App\Domain\Budgets\BudgetService;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCode;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use LogicException;

/**
 * A budget's lines. "Set line" adds a cost code or replaces its amount, and
 * "Remove" takes one off — both only while the budget is a draft, and both
 * through BudgetService. There is no inline edit: a typed amount must pass the
 * same checks as any other.
 */
class LinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    protected static ?string $title = 'Lines';

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('id')
            ->columns([
                TextColumn::make('costCode.code')->label('Cost code'),
                TextColumn::make('costCode.name')->label('Description'),
                TextColumn::make('amount')->money('PHP'),
            ])
            ->headerActions([
                Action::make('setLine')
                    ->label('Set line')
                    ->icon('heroicon-o-plus')
                    ->visible(fn (): bool => $this->budget()->status === BudgetStatus::Draft)
                    ->schema([
                        Select::make('cost_code_id')
                            ->label('Cost code')
                            ->searchable()
                            ->required()
                            ->options(fn (): array => CostCode::query()
                                ->where('organization_id', $this->budget()->project()->sole()->organization_id)
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->code.' — '.$code->name])
                                ->all())
                            ->helperText('Setting a cost code already on the budget replaces its amount.'),

                        TextInput::make('amount')
                            ->required()
                            ->rules(['numeric', 'gt:0']),
                    ])
                    ->action(function (array $data, Action $action): void {
                        try {
                            app(BudgetService::class)->setLine(
                                $this->budget(),
                                CostCode::query()->findOrFail($data['cost_code_id']),
                                (string) $data['amount'],
                                $this->actingUser(),
                            );
                        } catch (InvalidBudgetDetail|DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }

                        Notification::make()->success()->title('Line set.')->send();
                    }),
            ])
            ->recordActions([
                Action::make('removeLine')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->visible(fn (): bool => $this->budget()->status === BudgetStatus::Draft)
                    ->requiresConfirmation()
                    ->action(function (BudgetLine $record, Action $action): void {
                        try {
                            app(BudgetService::class)->removeLine($record, $this->actingUser());
                        } catch (DomainException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();
                            $action->halt();
                        }
                    }),
            ]);
    }

    private function budget(): Budget
    {
        $owner = $this->getOwnerRecord();

        if (! $owner instanceof Budget) {
            throw new LogicException('The lines table has no budget.');
        }

        return $owner->refresh();
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
