<?php

namespace App\Filament\Resources\Approvals;

use App\Domain\Approvals\ApprovalDecision;
use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Approvals\Pages\ListApprovals;
use App\Filament\Resources\Approvals\Tables\ApprovalsTable;
use App\Models\Approval;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The unified approvals inbox — PLAN.md §3: "one inbox, not seven".
 *
 * Seven inboxes is the default outcome: each chain grows its own pending screen
 * and an approver working across procurement and payroll has to remember to
 * check both. The approvals table is polymorphic so that this can be one list,
 * and this resource is the list.
 *
 * There is no create or edit page. An approval is opened by the Approvals
 * service when a document is submitted, and closed by a decision — it is never
 * something a person types into existence, so offering a form would invite
 * exactly the kind of hand-made approval the authority matrix exists to prevent.
 */
class ApprovalResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Approval::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxStack;

    protected static ?string $navigationLabel = 'Approvals inbox';

    protected static ?string $recordTitleAttribute = 'document_number';

    public static function table(Table $table): Table
    {
        return ApprovalsTable::configure($table);
    }

    /**
     * An inbox shows YOUR work.
     *
     * Scoped to the roles the signed-in user actually holds, and to steps that
     * are still pending. A row an approver has to skip past is a row that makes
     * the real one easier to miss, and a decided approval belongs in a history
     * view rather than a queue.
     *
     * Scoped here, on the resource's own query, rather than in the table: this
     * way the count badge, any future widget and the record-resolution used by
     * actions all inherit the same restriction. A scope applied only to the
     * visible table leaves the underlying records addressable.
     */
    public static function getEloquentQuery(): Builder
    {
        $roles = auth()->user()?->getRoleNames()->all() ?? [];

        return parent::getEloquentQuery()
            ->where('decision', ApprovalDecision::Pending)
            ->whereIn('approver_role', $roles);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = static::getEloquentQuery()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApprovals::route('/'),
        ];
    }
}
