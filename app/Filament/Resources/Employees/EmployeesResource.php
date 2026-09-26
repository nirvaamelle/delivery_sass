<?php

namespace App\Filament\Resources\Employees;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Filament\Resources\Employees\RelationManagers\AllowancesRelationManager;
use App\Filament\Resources\Employees\RelationManagers\LeaveRecordsRelationManager;
use App\Filament\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Resources\Employees\Tables\EmployeesTable;
use App\Models\Employee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The 201 file — the register, and adding and editing employees.
 *
 * This was read-only, like every document screen in the build. That was right
 * for payroll runs, DTRs and disbursements, which are the output of a checked
 * process, and wrong for employees: a person is master data, and HR had no way
 * to add one or correct a name except the database.
 *
 * What it still refuses, and why:
 *
 *   - **Government numbers, bank account and rates are never displayed** — on
 *     the register or in the form. They are write-only (EmployeeForm).
 *   - **A rate is a dated history**, set by its own action on the edit page,
 *     never a field that overwrites — a raise in May must not restate April.
 *   - **Separation is its own act with a required reason**, never a status
 *     dropdown, and there is no delete: payroll lines point at these rows.
 *
 * Every save goes through EmployeeService, so the screen enforces exactly what
 * the service does. Who may open it is config/access.php (Payroll group).
 */
class EmployeesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Employee::class;

    protected static ?string $recordTitleAttribute = 'employee_number';

    protected static ?string $navigationLabel = 'Employees';

    protected static string|UnitEnum|null $navigationGroup = 'Payroll';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            AllowancesRelationManager::class,
            LeaveRecordsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }
}
