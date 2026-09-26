<?php

namespace App\Filament\Resources\ReceivingReports\Pages;

use App\Domain\Procurement\PurchaseOrderStatus;
use App\Domain\Procurement\ReceivingService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Models\PurchaseOrder;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Receiving a delivery against an order — where the storekeeper's count enters
 * the system, and the only place it can.
 *
 * **The quantity received is typed; nothing else is.** What was ordered and what
 * remains outstanding are read from the order, because a delivery that could
 * declare its own expected quantity could never be short.
 *
 * Over-delivery is refused by the service: goods nobody ordered, on a site that
 * will be invoiced for them.
 */
class ListReceivingReports extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ReceivingReportsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('receiveDelivery')
                ->label('Receive a delivery')
                ->icon('heroicon-o-truck')
                ->modalWidth('3xl')
                ->modalDescription('Count what actually arrived. What was ordered is read from the purchase order, and a delivery larger than the balance is refused.')
                ->schema([
                    Select::make('purchase_order_id')
                        ->label('Purchase order')
                        ->searchable()
                        ->required()
                        ->live()
                        // Countersigned or approved: goods arriving against a
                        // draft order were never authorised by anybody.
                        ->options(fn (): array => PurchaseOrder::query()
                            ->whereIn('status', [PurchaseOrderStatus::Approved, PurchaseOrderStatus::Countersigned])
                            ->with('vendor')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (PurchaseOrder $order): array => [
                                $order->getKey() => $order->number.' — '.$order->vendor->name,
                            ])
                            ->all()),

                    TextInput::make('delivery_receipt_number')
                        ->label('Delivery receipt number')
                        ->required()
                        ->maxLength(255)
                        ->helperText('The vendor\'s own DR number, as written on the paper that came with the goods.'),

                    Repeater::make('lines')
                        ->label('What arrived')
                        ->required()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->schema([
                            Select::make('purchase_order_line_id')
                                ->label('Ordered line')
                                ->required()
                                ->options(fn (callable $get): array => self::orderedLines($get('../../purchase_order_id'))),

                            TextInput::make('quantity_received')
                                ->label('Quantity received')
                                ->required()
                                ->rules(['numeric', 'gt:0']),

                            TextInput::make('remarks')->maxLength(255),
                        ])
                        ->columns(3),

                    Textarea::make('remarks')->rows(2),
                ])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();

                    $lines = array_map(fn (array $line): array => [
                        'purchase_order_line_id' => (int) $line['purchase_order_line_id'],
                        'quantity_received' => (string) $line['quantity_received'],
                        'remarks' => (string) ($line['remarks'] ?? ''),
                    ], $data['lines'] ?? []);

                    try {
                        app(ReceivingService::class)->receive(
                            PurchaseOrder::query()->findOrFail($data['purchase_order_id']),
                            (string) $data['delivery_receipt_number'],
                            $lines,
                            $user instanceof User ? $user : null,
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title('The delivery was refused')->body($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Delivery received.')->send();
                }),
        ];
    }

    /**
     * The lines of the chosen order, with what is still outstanding on each.
     *
     * @return array<int, string>
     */
    private static function orderedLines(mixed $orderId): array
    {
        if (blank($orderId)) {
            return [];
        }

        $order = PurchaseOrder::query()->with('lines')->find($orderId);

        if ($order === null) {
            return [];
        }

        return $order->lines
            ->mapWithKeys(fn ($line): array => [
                $line->getKey() => sprintf('%s — ordered %s', $line->description, rtrim(rtrim((string) $line->quantity, '0'), '.')),
            ])
            ->all();
    }
}
