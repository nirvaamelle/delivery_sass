<?php

namespace App\Filament\Resources\ApVouchers\Pages;

use App\Domain\Procurement\PayablesService;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\ApVouchers\ApVouchersResource;
use App\Models\ThreeWayMatch;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

/**
 * Raising a voucher takes two facts and computes the rest.
 *
 * The clerk chooses the match and the withholding code. Gross comes from the
 * matched invoice, the tax from the rate table, and the advance recovery from
 * what the vendor still owes against that order — none of them typed, because a
 * typed net is a payment nobody can trace back to a document.
 */
class ListApVouchers extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = ApVouchersResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('raiseVoucher')
                ->label('Raise a voucher')
                ->icon('heroicon-o-document-plus')
                ->modalDescription('Gross, withholding and advance recovery are computed from the match, the rate table and the advances already released.')
                ->schema([
                    Select::make('three_way_match_id')
                        ->label('Matched invoice')
                        ->searchable()
                        ->required()
                        // One voucher per match: the unique key on the table says
                        // so, and offering a matched one twice invites the error.
                        ->options(fn (): array => ThreeWayMatch::query()
                            ->whereDoesntHave('voucher')
                            ->with('purchaseOrder.vendor')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (ThreeWayMatch $match): array => [
                                $match->getKey() => sprintf(
                                    '%s — invoice %s — %s',
                                    $match->number,
                                    $match->invoice_reference,
                                    $match->purchaseOrder->vendor->name,
                                ),
                            ])
                            ->all()),

                    Select::make('withholding_code')
                        ->label('Withholding')
                        ->required()
                        // PLACEHOLDER: Part D item 14 — the rates behind these
                        // codes are the build's, not the client's accountant's.
                        ->options(fn (): array => collect(array_keys((array) config('withholding.rates', [])))
                            ->mapWithKeys(fn (string $code): array => [$code => ucfirst($code).' — '.self::ratePercent($code)])
                            ->all())
                        ->helperText('Expanded withholding tax. The rate is applied to the gross invoice.'),
                ])
                ->action(function (array $data, Action $action): void {
                    $user = auth()->user();

                    try {
                        app(PayablesService::class)->raise(
                            ThreeWayMatch::query()->findOrFail($data['three_way_match_id']),
                            (string) $data['withholding_code'],
                            $user instanceof User ? $user : null,
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Voucher raised.')->send();
                }),
        ];
    }

    private static function ratePercent(string $code): string
    {
        $rate = (string) config('withholding.rates.'.$code, '0');

        return rtrim(rtrim(bcmul($rate, '100', 4), '0'), '.').'%';
    }
}
