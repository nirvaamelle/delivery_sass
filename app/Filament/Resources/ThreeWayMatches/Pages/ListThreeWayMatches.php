<?php

namespace App\Filament\Resources\ThreeWayMatches\Pages;

use App\Domain\Procurement\MatchFailedException;
use App\Domain\Procurement\ThreeWayMatchService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ThreeWayMatches\ThreeWayMatchesResource;
use App\Models\ReceivingReport;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Matching an invoice is the control, so it is an action and not a form.
 *
 * The clerk supplies only what the invoice itself says — its reference and its
 * amount. Everything the match is checked against (what was ordered, what was
 * received, what was accepted on inspection) is read from the documents, never
 * typed, because a typed "accepted quantity" is exactly the number a short
 * delivery would be hidden behind.
 */
class ListThreeWayMatches extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ThreeWayMatchesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('matchInvoice')
                ->label('Match an invoice')
                ->icon('heroicon-o-scale')
                ->modalDescription('The order and the receiving report are read from the documents. Only the invoice is typed.')
                ->schema([
                    Select::make('receiving_report_id')
                        ->label('Receiving report')
                        ->searchable()
                        ->required()
                        // Inspected receipts with no match yet: a match needs the
                        // accepted quantity, and one receipt matches once.
                        ->options(fn (): array => ReceivingReport::query()
                            ->whereHas('inspection')
                            ->whereDoesntHave('threeWayMatch')
                            ->with('purchaseOrder')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (ReceivingReport $report): array => [
                                $report->getKey() => $report->number.' — PO '.$report->purchaseOrder->number,
                            ])
                            ->all())
                        ->helperText('Only receipts that have been inspected and not yet matched.'),

                    TextInput::make('invoice_reference')
                        ->label('Vendor invoice number')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('invoice_amount')
                        ->label('Invoice amount')
                        ->required()
                        ->rules(['numeric', 'gt:0']),
                ])
                ->action(function (array $data, Action $action): void {
                    $report = ReceivingReport::query()->findOrFail($data['receiving_report_id']);
                    $user = auth()->user();

                    try {
                        app(ThreeWayMatchService::class)->match(
                            $report->purchaseOrder()->sole(),
                            $report,
                            (string) $data['invoice_reference'],
                            (string) $data['invoice_amount'],
                            $user instanceof User ? $user : null,
                        );
                    } catch (MatchFailedException $e) {
                        // The refusal IS the control. It is shown in full, because
                        // the clerk's next step depends on which of the three
                        // documents disagrees.
                        Notification::make()->danger()->title('The invoice does not match')->body($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Matched.')->send();
                }),
        ];
    }
}
