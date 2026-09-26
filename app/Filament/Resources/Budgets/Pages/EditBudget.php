<?php

namespace App\Filament\Resources\Budgets\Pages;

use App\Domain\Budgets\BudgetService;
use App\Domain\Budgets\BudgetStatus;
use App\Domain\Budgets\InvalidBudgetDetail;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Budgets\BudgetsResource;
use App\Models\Budget;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * A budget's name and lines while drafted; "Open budget" and "Close budget" as
 * acts. No delete — an opened budget is what requisitions were gated on.
 */
class EditBudget extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = BudgetsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openBudget')
                ->label('Open budget')
                ->icon('heroicon-o-lock-open')
                ->color('success')
                ->visible(fn (): bool => $this->budget()->status === BudgetStatus::Draft)
                ->requiresConfirmation()
                ->modalDescription('Once open, the lines are fixed: purchase requisitions and variance reports resolve against them.')
                ->action(fn (Action $action) => $this->attempt($action, 'Budget opened.', fn () => app(BudgetService::class)->open($this->budget(), $this->actingUser()))),

            Action::make('closeBudget')
                ->label('Close budget')
                ->icon('heroicon-o-lock-closed')
                ->color('warning')
                ->visible(fn (): bool => $this->budget()->status === BudgetStatus::Open)
                ->requiresConfirmation()
                ->modalDescription('The project will have no open budget until another is opened, and requisitions will be refused.')
                ->action(fn (Action $action) => $this->attempt($action, 'Budget closed.', fn () => app(BudgetService::class)->close($this->budget(), $this->actingUser()))),
        ];
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Budget) {
            throw new LogicException('The budget edit page was given something other than a budget.');
        }

        try {
            return app(BudgetService::class)->rename($record, (string) ($data['name'] ?? $record->name), $this->actingUser());
        } catch (InvalidBudgetDetail $e) {
            throw ValidationException::withMessages(['data.'.$e->field => $e->getMessage()]);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['data.name' => $e->getMessage()]);
        }
    }

    private function attempt(Action $action, string $success, callable $act): void
    {
        try {
            $act();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $action->halt();
        }

        $this->budget()->refresh();
        Notification::make()->success()->title($success)->send();
    }

    private function budget(): Budget
    {
        $record = $this->getRecord();

        if (! $record instanceof Budget) {
            throw new LogicException('The budget edit page has no budget.');
        }

        return $record;
    }

    private function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
