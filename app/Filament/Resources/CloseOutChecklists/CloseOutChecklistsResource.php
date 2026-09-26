<?php

namespace App\Filament\Resources\CloseOutChecklists;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\CloseOutChecklists\Pages\ListCloseOutChecklists;
use App\Filament\Resources\CloseOutChecklists\Pages\ViewCloseOutChecklist;
use App\Filament\Resources\CloseOutChecklists\Tables\CloseOutChecklistsTable;
use App\Models\CloseOutChecklist;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The close-out checklist — slide 9's structural obligation, on screen.
 *
 * The one Phase 5 resource with a second page, and it earns it: the exit gate
 * asks that "the close-out report names who cleared each item and when", and a
 * list of checklists does not say that. The report is per project, panel by
 * panel, and the view page is where it is actually read.
 *
 * Read-only. A line is certified through the service, which refuses a signature
 * the evidence contradicts; a form would put the tick back.
 */
class CloseOutChecklistsResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = CloseOutChecklist::class;

    protected static ?string $recordTitleAttribute = 'number';

    protected static ?string $navigationLabel = 'Close-out checklists';

    protected static string|UnitEnum|null $navigationGroup = 'Close-out';

    protected static ?int $navigationSort = 80;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    public static function table(Table $table): Table
    {
        return CloseOutChecklistsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCloseOutChecklists::route('/'),
            'view' => ViewCloseOutChecklist::route('/{record}'),
        ];
    }
}
