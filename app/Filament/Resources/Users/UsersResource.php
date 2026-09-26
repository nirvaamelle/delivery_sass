<?php

namespace App\Filament\Resources\Users;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\RelationManagers\ProjectAssignmentsRelationManager;
use App\Filament\Resources\Users\Schemas\UserForm;
use App\Filament\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Sign-in accounts: who they are, what they open (roles), and which projects
 * they see (assignments).
 *
 * **The administrator's screen alone** (config/access.php). Whoever edits roles
 * decides what everybody else can open, including this screen.
 *
 * Saves through UserAccountService and ProjectAccessService. No password is ever
 * shown or pre-filled; after creation it is replaced by its own action. No
 * delete — approvals, postings and the activity log name these accounts.
 */
class UsersResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = User::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Accounts';

    protected static ?string $modelLabel = 'account';

    protected static string|UnitEnum|null $navigationGroup = 'Setup';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ProjectAssignmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
