<?php

namespace App\Filament\Resources\PurchaseOrders\Tables;

use App\Domain\Procurement\PurchaseOrderService;
use App\Domain\Procurement\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class PurchaseOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),

                TextColumn::make('vendor.name')->label('Vendor')->searchable(),
                TextColumn::make('project.code')->label('Project')->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (PurchaseOrderStatus $state): string => match ($state) {
                        PurchaseOrderStatus::Approved, PurchaseOrderStatus::Countersigned => 'success',
                        PurchaseOrderStatus::Cancelled => 'danger',
                        PurchaseOrderStatus::Submitted => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('total_amount')->label('Amount')->money('PHP')->sortable(),

                IconColumn::make('sole_source')->label('Sole source')->boolean(),

                TextColumn::make('delivery_date')->label('Promised')->date()->sortable(),

                /*
                 * Computed from the order lines rather than stored. "How much of
                 * this order is still outstanding" is the question the three-way
                 * match asks, and a cached figure would let the screen and the
                 * match disagree about the same order.
                 */
                TextColumn::make('outstanding')
                    ->label('Outstanding')
                    ->state(fn (PurchaseOrder $record): string => $record->lines()
                        ->get()
                        ->reduce(
                            fn (string $carry, $line): string => bcadd(
                                $carry,
                                bcsub((string) $line->quantity, (string) $line->quantity_received, 4),
                                4,
                            ),
                            '0.0000',
                        )),
            ])
            ->recordActions([
                /*
                 * Submit routes the order through the matrix; approving is the
                 * inbox's. Countersigning is the vendor accepting, and it is what
                 * mobilization waits for — so it is refused until the company has
                 * approved, or an order that never met the matrix could open a
                 * site.
                 */
                Action::make('submitOrder')
                    ->label('Submit for approval')
                    ->icon('heroicon-o-paper-airplane')
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Draft)
                    ->requiresConfirmation()
                    ->action(fn (PurchaseOrder $record, Action $action) => self::attempt(
                        $action,
                        'Submitted for approval.',
                        fn () => app(PurchaseOrderService::class)->submit($record),
                    )),

                Action::make('countersignOrder')
                    ->label('Record vendor countersignature')
                    ->icon('heroicon-o-pencil-square')
                    ->color('success')
                    ->visible(fn (PurchaseOrder $record): bool => $record->status === PurchaseOrderStatus::Approved)
                    ->schema([
                        DatePicker::make('countersigned_at')->label('Date countersigned')->required()->default(now()),
                        TextInput::make('signatory')->label('Who signed for the vendor')->required()->maxLength(255),
                    ])
                    ->action(fn (PurchaseOrder $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Countersignature recorded.',
                        fn () => app(PurchaseOrderService::class)->countersign(
                            $record,
                            Carbon::parse($data['countersigned_at']),
                            (string) $data['signatory'],
                        ),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(PurchaseOrderStatus::cases())
                        ->mapWithKeys(fn (PurchaseOrderStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
                TernaryFilter::make('sole_source')->label('Sole source'),
            ]);
    }

    /**
     * Run one act, and put a refusal on screen rather than in a stack trace.
     */
    private static function attempt(Action $action, string $success, callable $act): void
    {
        try {
            $act();
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->persistent()->send();
            $action->halt();
        }

        Notification::make()->success()->title($success)->send();
    }
}
