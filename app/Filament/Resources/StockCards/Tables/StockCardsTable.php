<?php

namespace App\Filament\Resources\StockCards\Tables;

use App\Domain\Procurement\StockService;
use App\Models\CostCode;
use App\Models\StockCard;
use App\Models\User;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StockCardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('item_description')
            ->columns([
                TextColumn::make('item_description')->label('Item')->searchable()->sortable(),
                TextColumn::make('project.code')->label('Project')->searchable(),
                TextColumn::make('costCode.code')->label('Cost code')->searchable(),
                TextColumn::make('unit'),

                /*
                 * Both figures are summed from the movements, never read from a
                 * column — the card deliberately has no stored balance. A screen
                 * that read one would be the first place the yard and its record
                 * could quietly disagree.
                 */
                TextColumn::make('on_hand')
                    ->label('On hand')
                    ->state(fn (StockCard $record): string => app(StockService::class)->onHand($record)),

                TextColumn::make('value_on_hand')
                    ->label('Value')
                    ->money('PHP')
                    ->state(fn (StockCard $record): string => app(StockService::class)->valueOnHand($record)),
            ])
            ->recordActions([
                /*
                 * Material leaves stock against a COST CODE, never against a
                 * project alone: the issue is what turns a warehouse balance into
                 * project cost, and a cost code is what the budget is checked
                 * against.
                 *
                 * Issuing more than is on hand is refused — a negative balance is
                 * either a theft nobody recorded or a count nobody did, and both
                 * need finding rather than hiding.
                 */
                Action::make('issueStock')
                    ->label('Issue to works')
                    ->icon('heroicon-o-arrow-up-on-square')
                    ->schema([
                        TextInput::make('quantity')
                            ->required()
                            ->rules(['numeric', 'gt:0'])
                            ->helperText(fn (StockCard $record): string => 'On hand: '
                                .rtrim(rtrim(app(StockService::class)->onHand($record), '0'), '.')),

                        Select::make('cost_code_id')
                            ->label('Charge to cost code')
                            ->searchable()
                            ->required()
                            ->options(fn (): array => CostCode::query()->orderBy('code')->get()
                                ->mapWithKeys(fn (CostCode $code): array => [$code->getKey() => $code->code.' — '.$code->name])
                                ->all()),

                        TextInput::make('issued_to')->label('Issued to')->maxLength(255),
                        Textarea::make('purpose')->rows(2),
                    ])
                    ->action(fn (StockCard $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Material issued.',
                        fn () => app(StockService::class)->issue(
                            $record,
                            (string) $data['quantity'],
                            (int) $data['cost_code_id'],
                            self::actingUser(),
                            filled($data['purpose'] ?? null) ? (string) $data['purpose'] : null,
                            filled($data['issued_to'] ?? null) ? (string) $data['issued_to'] : null,
                        ),
                    )),

                Action::make('countStock')
                    ->label('Physical count')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->modalDescription('Records what was actually counted. The variance against the book balance is computed, not typed.')
                    ->schema([
                        TextInput::make('counted_quantity')
                            ->label('Counted')
                            ->required()
                            ->rules(['numeric', 'gte:0'])
                            ->helperText(fn (StockCard $record): string => 'Book balance: '
                                .rtrim(rtrim(app(StockService::class)->onHand($record), '0'), '.')),

                        Textarea::make('remarks')->rows(2),
                    ])
                    ->action(fn (StockCard $record, array $data, Action $action) => self::attempt(
                        $action,
                        'Count recorded.',
                        fn () => app(StockService::class)->count(
                            $record,
                            (string) $data['counted_quantity'],
                            self::actingUser(),
                            filled($data['remarks'] ?? null) ? (string) $data['remarks'] : null,
                        ),
                    )),
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

    private static function actingUser(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
