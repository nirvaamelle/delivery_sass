<?php

namespace App\Filament\Resources\SalesInvoices\Pages;

use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\CollectionService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\SalesInvoices\SalesInvoicesResource;
use App\Models\Billing;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

/**
 * Invoicing an approved billing.
 *
 * Only approved billings are offered: an invoice against a returned one bills
 * the client for work they have just disputed. Retention is withheld here by the
 * contract's own rate, and the withholding code decides the tax the client
 * deducts at source — neither is typed as an amount.
 */
class ListSalesInvoices extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = SalesInvoicesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raiseInvoice')
                ->label('Invoice a billing')
                ->icon('heroicon-o-document-currency-dollar')
                ->modalDescription('Retention is withheld at the contract rate, and the tax the client withholds is computed from the code.')
                ->schema([
                    Select::make('billing_id')
                        ->label('Approved billing')
                        ->searchable()
                        ->required()
                        ->options(fn (): array => Billing::query()
                            ->where('status', BillingStatus::Approved)
                            ->whereDoesntHave('invoice')
                            ->whereHas('project')
                            ->with('project')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (Billing $billing): array => [
                                $billing->getKey() => sprintf(
                                    '%s — %s — %s',
                                    $billing->number,
                                    $billing->project->code,
                                    number_format((float) $billing->net_amount, 2),
                                ),
                            ])
                            ->all()),

                    DatePicker::make('issued_on')->label('Issued on')->required()->default(now()),

                    Select::make('withholding_code')
                        ->label('Client withholding')
                        ->placeholder('None')
                        // PLACEHOLDER: Part D item 14 — the build's rates.
                        ->options(fn (): array => collect(array_keys((array) config('withholding.rates', [])))
                            ->mapWithKeys(fn (string $code): array => [$code => ucfirst($code)])
                            ->all())
                        ->helperText('What the client deducts at source and remits on the company\'s behalf.'),
                ])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();

                    try {
                        app(CollectionService::class)->invoice(
                            Billing::query()->findOrFail($data['billing_id']),
                            Carbon::parse($data['issued_on']),
                            filled($data['withholding_code'] ?? null) ? (string) $data['withholding_code'] : null,
                            $user instanceof User ? $user : null,
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Invoice raised.')->send();
                }),
        ];
    }
}
