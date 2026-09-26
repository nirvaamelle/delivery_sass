<?php

namespace App\Filament\Resources\VendorAdvances\Pages;

use App\Domain\Procurement\PayablesService;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\VendorAdvances\VendorAdvancesResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Releasing an advance needs an order behind it and a purpose on it.
 *
 * Only approved and countersigned orders are offered: an advance against a draft
 * is money leaving for something nobody has awarded yet. The cumulative ceiling
 * (an order cannot advance more than it is worth) is PayablesService's, not the
 * form's — three advances of 40% each pass one at a time.
 */
class ListVendorAdvances extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = VendorAdvancesResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('releaseAdvance')
                ->label('Release an advance')
                ->icon('heroicon-o-arrow-up-tray')
                ->modalDescription('Money leaving before anything arrives. The purpose is recorded against it and is what an audit asks for first.')
                ->schema([
                    Select::make('purchase_order_id')
                        ->label('Purchase order')
                        ->searchable()
                        ->required()
                        ->options(fn (): array => PurchaseOrder::query()
                            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Countersigned])
                            ->with('vendor')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (PurchaseOrder $order): array => [
                                $order->getKey() => sprintf(
                                    '%s — %s — %s',
                                    $order->number,
                                    $order->vendor->name,
                                    number_format((float) $order->total_amount, 2),
                                ),
                            ])
                            ->all())
                        ->helperText('Approved and countersigned orders only.'),

                    TextInput::make('amount')
                        ->required()
                        ->rules(['numeric', 'gt:0'])
                        ->helperText('Everything advanced against one order, together, cannot exceed what the order is worth.'),

                    Textarea::make('purpose')
                        ->required()
                        ->rows(2)
                        ->helperText('Why the vendor is being paid before delivery.'),
                ])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();

                    try {
                        app(PayablesService::class)->releaseAdvance(
                            PurchaseOrder::query()->findOrFail($data['purchase_order_id']),
                            (string) $data['amount'],
                            (string) $data['purpose'],
                            $user instanceof User ? $user : null,
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Advance released.')->send();
                }),
        ];
    }
}
