<?php

namespace App\Filament\Resources\Billings\Pages;

use App\Domain\Billing\BillingScheduleService;
use App\Domain\Billing\BillingService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Billings\BillingsResource;
use App\Models\BillingMilestone;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

/**
 * Submitting a billing — slide 6's step, and the one with the most gates behind
 * it.
 *
 * Three refusals reach this screen, and each is shown in full because each needs
 * a different fix: the milestone's documents are incomplete (F4 — go and find
 * them), the works have not reached the milestone's threshold (measure again),
 * or a line is one the client already deducted and nobody has cleared.
 */
class ListBillings extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = BillingsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('submitBilling')
                ->label('Submit a billing')
                ->icon('heroicon-o-document-arrow-up')
                ->modalWidth('3xl')
                ->schema([
                    Select::make('billing_milestone_id')
                        ->label('Milestone')
                        ->searchable()
                        ->required()
                        // Only milestones on contracts the user can see, and only
                        // those not already billed.
                        ->options(fn (): array => BillingMilestone::query()
                            ->whereHas('schedule.contract.project')
                            ->whereDoesntHave('billings')
                            ->with('schedule.contract')
                            ->orderBy('billing_schedule_id')
                            ->orderBy('sequence')
                            ->get()
                            ->mapWithKeys(fn (BillingMilestone $milestone): array => [
                                $milestone->getKey() => sprintf(
                                    '%s — %s (%s%%) — %s',
                                    $milestone->schedule->contract->number,
                                    $milestone->name,
                                    $milestone->percentage,
                                    number_format((float) app(BillingScheduleService::class)->amountFor($milestone), 2),
                                ),
                            ])
                            ->all()),

                    DatePicker::make('period_start')->label('Period start'),
                    DatePicker::make('period_end')->label('Period end'),

                    Repeater::make('lines')
                        ->label('Billing lines')
                        ->required()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->addActionLabel('Add a line')
                        ->schema([
                            TextInput::make('line_key')
                                ->label('Line key')
                                ->required()
                                ->maxLength(255)
                                ->helperText('How the client refers to this item. A deducted line is blocked by this key.'),

                            TextInput::make('description')->required()->maxLength(255),

                            TextInput::make('amount')->required()->rules(['numeric', 'gt:0']),
                        ])
                        ->columns(3),
                ])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();

                    $lines = array_map(fn (array $line): array => [
                        'line_key' => (string) $line['line_key'],
                        'description' => (string) $line['description'],
                        'amount' => (string) $line['amount'],
                    ], $data['lines'] ?? []);

                    try {
                        app(BillingService::class)->submit(
                            BillingMilestone::query()->findOrFail($data['billing_milestone_id']),
                            $lines,
                            $user instanceof User ? $user : null,
                            filled($data['period_start'] ?? null) ? Carbon::parse($data['period_start']) : null,
                            filled($data['period_end'] ?? null) ? Carbon::parse($data['period_end']) : null,
                        );
                    } catch (DomainException $e) {
                        // Missing documents, an unreached threshold and a deducted
                        // line all land here, and each says what to do next.
                        Notification::make()->danger()->title('The billing was refused')->body($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Billing submitted.')->send();
                }),
        ];
    }
}
