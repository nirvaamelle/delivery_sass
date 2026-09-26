<?php

namespace App\Filament\Resources\Rfqs\Tables;

use App\Domain\Procurement\QuoteService;
use App\Domain\Procurement\RfqService;
use App\Domain\Procurement\RfqStatus;
use App\Domain\Procurement\SoleSourceReason;
use App\Domain\Procurement\SoleSourceService;
use App\Domain\Procurement\TabulationService;
use App\Models\Rfq;
use App\Models\Vendor;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RfqsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('number')->searchable()->sortable(),

                TextColumn::make('requisition.number')
                    ->label('Requisition')
                    ->searchable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (RfqStatus $state): string => match ($state) {
                        RfqStatus::Issued => 'success',
                        RfqStatus::Draft => 'gray',
                        RfqStatus::Cancelled => 'danger',
                        default => 'warning',
                    }),

                // The three-quote minimum is checked at issue, so the count is
                // the number somebody looking at a draft actually needs.
                TextColumn::make('recipients_count')
                    ->label('Invited')
                    ->counts('recipients'),

                /*
                 * On the screen because a sole-source RFQ escalates one approval
                 * tier and skips the three-quote minimum. An exception that is
                 * not visible on the list is an exception nobody reviews.
                 */
                IconColumn::make('sole_source')
                    ->label('Sole source')
                    ->boolean(),

                TextColumn::make('quotation_deadline')->label('Closes')->date()->sortable(),
            ])
            ->recordActions([
                /*
                 * The canvass, in the order slide 4 runs it: invite, issue,
                 * record what each vendor quoted, then tabulate. The abstract of
                 * canvass is COMPUTED from the quotes — it is the document that
                 * justifies the award, and a typed recommendation would justify
                 * nothing.
                 */
                Action::make('inviteVendor')
                    ->label('Invite a vendor')
                    ->icon('heroicon-o-user-plus')
                    ->visible(fn (Rfq $record): bool => $record->status === RfqStatus::Draft)
                    ->schema([
                        Select::make('vendor_id')
                            ->label('Vendor')
                            ->searchable()
                            ->required()
                            ->options(fn (): array => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->helperText('Accreditation is re-checked here: a lapsed certificate cannot be invited.'),
                    ])
                    ->action(fn (Rfq $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Vendor invited.',
                        fn () => app(RfqService::class)->invite($record, Vendor::query()->findOrFail($data['vendor_id'])),
                    )),

                Action::make('issueRfq')
                    ->label('Issue')
                    ->icon('heroicon-o-envelope')
                    ->visible(fn (Rfq $record): bool => $record->status === RfqStatus::Draft)
                    ->requiresConfirmation()
                    ->modalDescription('Sends the RFQ to everybody invited. Quotes can only be recorded once it is issued.')
                    ->action(fn (Rfq $record, Action $action) => self::attempt(
                        $action,
                        'RFQ issued.',
                        fn () => app(RfqService::class)->issue($record),
                    )),

                Action::make('recordQuote')
                    ->label('Record a quote')
                    ->icon('heroicon-o-currency-dollar')
                    ->visible(fn (Rfq $record): bool => $record->status === RfqStatus::Issued)
                    ->modalWidth('3xl')
                    ->schema([
                        Select::make('vendor_id')
                            ->label('Vendor')
                            ->required()
                            ->options(fn (Rfq $record): array => $record->recipients()
                                ->with('vendor')
                                ->get()
                                ->mapWithKeys(fn ($recipient): array => [$recipient->vendor_id => $recipient->vendor->name])
                                ->all())
                            ->helperText('Only vendors this RFQ was issued to.'),

                        Repeater::make('lines')
                            ->label('Quoted lines')
                            ->required()
                            ->minItems(1)
                            ->defaultItems(1)
                            ->schema([
                                TextInput::make('description')->required()->maxLength(255),
                                TextInput::make('quantity')->required()->rules(['numeric', 'gt:0']),
                                TextInput::make('unit_price')->label('Unit price')->required()->rules(['numeric', 'gte:0']),
                            ])
                            ->columns(3),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(fn (Rfq $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Quote recorded.',
                        fn () => app(QuoteService::class)->record(
                            $record,
                            Vendor::query()->findOrFail($data['vendor_id']),
                            array_map(fn (array $line): array => [
                                'description' => (string) $line['description'],
                                'quantity' => (string) $line['quantity'],
                                'unit_price' => (string) $line['unit_price'],
                            ], $data['lines'] ?? []),
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),

                Action::make('justifySoleSource')
                    ->label('Justify sole source')
                    ->icon('heroicon-o-exclamation-triangle')
                    ->color('warning')
                    ->visible(fn (Rfq $record): bool => $record->sole_source && $record->justification === null)
                    ->modalDescription('A sole source is approved one tier above the purchase it enables. Written first, signed after.')
                    ->schema([
                        Select::make('vendor_id')
                            ->label('The only vendor')
                            ->required()
                            ->options(fn (Rfq $record): array => $record->recipients()
                                ->with('vendor')
                                ->get()
                                ->mapWithKeys(fn ($recipient): array => [$recipient->vendor_id => $recipient->vendor->name])
                                ->all()),

                        Select::make('reason')
                            ->required()
                            ->options(collect(SoleSourceReason::cases())
                                ->mapWithKeys(fn (SoleSourceReason $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                                ->all()),

                        Textarea::make('narrative')
                            ->required()
                            ->rows(3)
                            ->helperText('Why no other vendor can supply this. An approver reads exactly this.'),

                        TextInput::make('amount')->required()->rules(['numeric', 'gt:0']),
                    ])
                    ->action(fn (Rfq $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Justification filed, and sent for approval one tier up.',
                        fn () => app(SoleSourceService::class)->justify(
                            $record,
                            Vendor::query()->findOrFail($data['vendor_id']),
                            SoleSourceReason::from((string) $data['reason']),
                            (string) $data['narrative'],
                            (string) $data['amount'],
                        ),
                    )),

                Action::make('tabulate')
                    ->label('Abstract of canvass')
                    ->icon('heroicon-o-table-cells')
                    ->color('success')
                    ->visible(fn (Rfq $record): bool => $record->status === RfqStatus::Issued && $record->tabulation === null)
                    ->requiresConfirmation()
                    ->modalDescription('Computed from the quotes on file, and it recommends the lowest. The purchase order can only be awarded to that vendor.')
                    ->schema([Textarea::make('remarks')->rows(2)])
                    ->action(fn (Rfq $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Abstract of canvass produced.',
                        fn () => app(TabulationService::class)->tabulate(
                            $record,
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),

                Action::make('cancelRfq')
                    ->label('Cancel')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Rfq $record): bool => ! in_array($record->status, [RfqStatus::Cancelled, RfqStatus::Closed], true))
                    ->schema([Textarea::make('reason')->required()->rows(2)])
                    ->action(fn (Rfq $record, array $data, Action $action) => self::attempt(
                        $action,
                        'RFQ cancelled.',
                        fn () => app(RfqService::class)->cancel($record, (string) $data['reason']),
                    )),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(RfqStatus::cases())
                        ->mapWithKeys(fn (RfqStatus $case): array => [$case->value => ucfirst($case->value)])
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
