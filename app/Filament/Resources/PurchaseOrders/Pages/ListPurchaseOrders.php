<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Domain\Procurement\PurchaseOrderService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\PurchaseOrders\PurchaseOrdersResource;
use App\Models\BidTabulation;
use App\Models\CostCode;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use InvalidArgumentException;

/**
 * Raising an order against an abstract of canvass.
 *
 * **The vendor is not chosen here.** The tabulation recommends one, and the
 * service refuses an award to anybody else — otherwise the abstract of canvass
 * is decorative and the lowest quote means nothing. A different vendor needs a
 * new canvass, which is the point.
 *
 * Accreditation is re-checked at award, and a sole source must have a fully
 * approved justification on file.
 */
class ListPurchaseOrders extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = PurchaseOrdersResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raiseOrder')
                ->label('Raise an order')
                ->icon('heroicon-o-document-plus')
                ->modalWidth('3xl')
                ->modalDescription('Awarded to the vendor the abstract of canvass recommends. Awarding to anybody else needs a new canvass.')
                ->schema([
                    Select::make('bid_tabulation_id')
                        ->label('Abstract of canvass')
                        ->searchable()
                        ->required()
                        ->options(fn (): array => BidTabulation::query()
                            ->whereDoesntHave('purchaseOrder')
                            ->with('recommendedVendor')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (BidTabulation $tabulation): array => [
                                $tabulation->getKey() => sprintf(
                                    '%s — recommends %s',
                                    $tabulation->number,
                                    $tabulation->recommendedVendor->name,
                                ),
                            ])
                            ->all()),

                    Repeater::make('lines')
                        ->label('Ordered lines')
                        ->required()
                        ->minItems(1)
                        ->defaultItems(1)
                        ->schema([
                            TextInput::make('description')->required()->maxLength(255),
                            TextInput::make('quantity')->required()->rules(['numeric', 'gt:0']),
                            TextInput::make('unit_price')->label('Unit price')->required()->rules(['numeric', 'gte:0']),

                            Select::make('cost_code_id')
                                ->label('Cost code')
                                ->searchable()
                                ->options(fn (): array => CostCode::query()->orderBy('code')->get()
                                    ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->code.' — '.$code->name])
                                    ->all())
                                ->helperText('Defaults to the requisition\'s.'),
                        ])
                        ->columns(4),
                ])
                ->action(function (array $data, Action $action): void {
                    $lines = array_map(function (array $line): array {
                        $row = [
                            'description' => (string) $line['description'],
                            'quantity' => (string) $line['quantity'],
                            'unit_price' => (string) $line['unit_price'],
                        ];

                        if (filled($line['cost_code_id'] ?? null)) {
                            $row['cost_code_id'] = (int) $line['cost_code_id'];
                        }

                        return $row;
                    }, $data['lines'] ?? []);

                    try {
                        app(PurchaseOrderService::class)->raise(
                            BidTabulation::query()->findOrFail($data['bid_tabulation_id']),
                            $lines,
                        );
                    } catch (DomainException|InvalidArgumentException $e) {
                        Notification::make()->danger()->title('The order was refused')->body($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Order raised as a draft.')->send();
                }),
        ];
    }
}
