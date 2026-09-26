<?php

namespace App\Filament\Resources\Rfqs\Pages;

use App\Domain\Procurement\RfqService;
use App\Domain\Requisitions\RequisitionStatus;
use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Rfqs\RfqsResource;
use App\Models\PurchaseRequisition;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

/**
 * Opening a canvass against an approved requisition.
 *
 * Only APPROVED requisitions are offered, and the service refuses the rest: a
 * canvass against an unapproved requisition is a price for something nobody has
 * authorised buying.
 *
 * **Sole source is declared here, at the start.** It escalates the approval one
 * tier and requires a written justification before any order can be awarded —
 * deciding it after the quotes are in is how a single-quote purchase gets
 * described as a canvass.
 */
class ListRfqs extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = RfqsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openRfq')
                ->label('Open a canvass')
                ->icon('heroicon-o-inbox-arrow-down')
                ->schema([
                    Select::make('purchase_requisition_id')
                        ->label('Approved requisition')
                        ->searchable()
                        ->required()
                        ->options(fn (): array => PurchaseRequisition::query()
                            ->where('status', RequisitionStatus::Approved)
                            ->whereDoesntHave('rfq')
                            ->orderByDesc('id')
                            ->get()
                            ->mapWithKeys(fn (PurchaseRequisition $pr): array => [
                                $pr->getKey() => $pr->number.' — '.number_format((float) $pr->total_amount, 2),
                            ])
                            ->all()),

                    DatePicker::make('quotation_deadline')
                        ->label('Quotations due')
                        ->required()
                        ->helperText('The date invited vendors must quote by.'),

                    Toggle::make('sole_source')
                        ->label('Sole source')
                        ->helperText('Escalates approval one tier and requires a written justification before any award.'),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        app(RfqService::class)->open(
                            PurchaseRequisition::query()->findOrFail($data['purchase_requisition_id']),
                            Carbon::parse($data['quotation_deadline']),
                            (bool) ($data['sole_source'] ?? false),
                        );
                    } catch (DomainException $e) {
                        Notification::make()->danger()->title($e->getMessage())->persistent()->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title('Canvass opened.')->send();
                }),
        ];
    }
}
