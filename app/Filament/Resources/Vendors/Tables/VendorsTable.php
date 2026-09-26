<?php

namespace App\Filament\Resources\Vendors\Tables;

use App\Domain\Vendors\VendorService;
use App\Domain\Vendors\VendorStatus;
use App\Domain\Vendors\VendorSuspensionReason;
use App\Models\Vendor;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

/**
 * The vendor register.
 *
 * Every action here calls VendorService. None of them writes a status column
 * directly — the service is what knows accreditation runs twelve months, and
 * what refuses to bring a removed vendor back.
 *
 * The column that earns its place is "RFQ eligible": it answers the question
 * PLAN.md §5 actually asks, and it is computed rather than stored, so it cannot
 * drift from the truth the RFQ gate enforces.
 */
class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->color(fn (VendorStatus $state): string => match ($state) {
                        VendorStatus::Accredited => 'success',
                        VendorStatus::Suspended => 'warning',
                        VendorStatus::Removed => 'danger',
                        VendorStatus::Pending => 'gray',
                    }),

                TextColumn::make('accreditation_expiry')
                    ->label('Accredited until')
                    ->state(fn (Vendor $record): string => $record->accreditations()
                        ->orderByDesc('expires_at')
                        ->value('expires_at')?->toDateString() ?? '—'),

                // Computed, never stored. This is the same question the RFQ gate
                // asks, so answering it from a column would let the register and
                // the gate disagree — and the register is what people trust.
                IconColumn::make('rfq_eligible')
                    ->label('RFQ eligible')
                    ->boolean()
                    ->state(fn (Vendor $record): bool => app(VendorService::class)->isAccredited($record)),

                TextColumn::make('status_reason')
                    ->label('Reason')
                    ->badge()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(
                    collect(VendorStatus::cases())
                        ->mapWithKeys(fn (VendorStatus $case): array => [$case->value => ucfirst(str_replace('_', ' ', $case->value))])
                        ->all()
                ),
            ])
            ->recordActions([
                Action::make('accredit')
                    ->label(fn (Vendor $record): string => $record->accreditations()->exists() ? 'Renew' : 'Accredit')
                    ->icon(Heroicon::OutlinedCheckBadge)
                    ->color('success')
                    ->visible(fn (Vendor $record): bool => $record->status !== VendorStatus::Removed)
                    ->schema([
                        DatePicker::make('accredited_at')
                            ->label('Accredited from')
                            ->required()
                            ->default(now())
                            ->helperText('Runs twelve months from this date.'),

                        TextInput::make('certificate_reference')->maxLength(255),
                    ])
                    ->action(fn (Vendor $record, array $data) => app(VendorService::class)->accredit(
                        $record,
                        Carbon::parse($data['accredited_at']),
                        $data['certificate_reference'] ?: null,
                        auth()->user(),
                    )),

                Action::make('suspend')
                    ->icon(Heroicon::OutlinedPauseCircle)
                    ->color('warning')
                    ->visible(fn (Vendor $record): bool => in_array(
                        $record->status,
                        [VendorStatus::Accredited, VendorStatus::Pending],
                        true,
                    ))
                    ->schema([
                        Select::make('reason')
                            // Required. A suspension with no recorded reason is
                            // the one nobody can explain later.
                            ->required()
                            ->options(
                                collect(VendorSuspensionReason::cases())
                                    ->reject(fn (VendorSuspensionReason $case): bool => $case === VendorSuspensionReason::FalsifiedDocuments)
                                    ->mapWithKeys(fn (VendorSuspensionReason $case): array => [
                                        $case->value => ucfirst(str_replace('_', ' ', $case->value)),
                                    ])
                                    ->all()
                            ),

                        Textarea::make('notes'),
                    ])
                    ->action(fn (Vendor $record, array $data) => app(VendorService::class)->suspend(
                        $record,
                        VendorSuspensionReason::from($data['reason']),
                        auth()->user(),
                        $data['notes'] ?: null,
                    )),

                Action::make('clearSuspension')
                    ->label('Clear suspension')
                    ->icon(Heroicon::OutlinedArrowPath)
                    // Hidden unless the vendor is actually suspended. An action
                    // that always fails is a bug report waiting to be filed —
                    // and on a removed vendor the service refuses outright.
                    ->visible(fn (Vendor $record): bool => $record->status === VendorStatus::Suspended)
                    ->schema([Textarea::make('notes')])
                    ->action(fn (Vendor $record, array $data) => app(VendorService::class)->clearSuspension(
                        $record,
                        auth()->user(),
                        $data['notes'] ?: null,
                    )),

                Action::make('remove')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Removal is permanent. PLAN.md §5 reserves it for falsified documents, and a removed vendor cannot be reinstated.')
                    ->visible(fn (Vendor $record): bool => $record->status !== VendorStatus::Removed)
                    ->schema([Textarea::make('notes')->required()])
                    ->action(fn (Vendor $record, array $data) => app(VendorService::class)->remove(
                        $record,
                        auth()->user(),
                        $data['notes'],
                    )),

                EditAction::make(),
            ]);
    }
}
