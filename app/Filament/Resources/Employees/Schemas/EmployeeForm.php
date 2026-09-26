<?php

namespace App\Filament\Resources\Employees\Schemas;

use App\Domain\Hris\GovernmentIds;
use App\Domain\Hris\PayBasis;
use App\Models\Employee;
use App\Models\Organization;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The 201 file form — add and edit.
 *
 * **Government numbers and the bank account are write-only.** They are
 * encrypted at rest (PLAN.md §3), and a form that decrypted them onto the page
 * would undo that on a screen HR, finance and admin all open. The field is
 * always empty when the page loads: leave it blank to keep what is stored, type
 * a value to replace it. The helper text says whether one is on file.
 *
 * **Locked on edit:** the employee number (timekeeping imports and payroll lines
 * match on it), the date hired (the contract gate reads it) and the organization
 * (it decides whose payroll a person is on). Disabled fields are not submitted,
 * and EmployeeService refuses them anyway.
 *
 * **Not here at all:** the pay rate after hire, which is a dated history set by
 * its own action, and status, which changes only through separation with a
 * reason. A dropdown for either would let history be overwritten.
 */
class EmployeeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Employment')
                ->columns(2)
                ->schema([
                    Select::make('organization_id')
                        ->label('Company')
                        ->options(fn (): array => Organization::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->default(fn (): ?int => Organization::query()->count() === 1 ? (int) Organization::query()->value('id') : null)
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('employee_number')
                        ->label('Employee number')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Timekeeping imports match on this, so it cannot be changed after hire.'),

                    DatePicker::make('date_hired')
                        ->label('Date hired')
                        ->required()
                        ->disabledOn('edit'),

                    TextInput::make('position')->maxLength(255),
                    TextInput::make('department')->maxLength(255),
                ]),

            Section::make('Name')
                ->columns(3)
                ->schema([
                    TextInput::make('first_name')->label('First name')->required()->maxLength(255),
                    TextInput::make('middle_name')->label('Middle name')->maxLength(255),
                    TextInput::make('last_name')->label('Last name')->required()->maxLength(255),
                ]),

            Section::make('Personal')
                ->columns(2)
                ->schema([
                    DatePicker::make('date_of_birth')->label('Date of birth'),
                    TextInput::make('contact_number')->label('Contact number')->tel()->maxLength(255),
                    TextInput::make('emergency_contact')->label('Emergency contact')->maxLength(255),
                    Textarea::make('address')->columnSpanFull(),
                ]),

            Section::make('Government numbers')
                ->description('Encrypted at rest and never displayed. Leave a field blank to keep what is on file; type a new value to replace it.')
                ->columns(2)
                ->schema([
                    self::governmentNumber('sss_number', 'SSS number', 'NN-NNNNNNN-N'),
                    self::governmentNumber('philhealth_number', 'PhilHealth number', 'NN-NNNNNNNNN-N'),
                    self::governmentNumber('pagibig_number', 'Pag-IBIG number', 'NNNN-NNNN-NNNN'),
                    self::governmentNumber('tin', 'TIN', 'NNN-NNN-NNN or NNN-NNN-NNN-NNN'),
                    self::writeOnly(TextInput::make('bank_account_number')->label('Bank account number')->maxLength(255)),
                ]),

            // Hire only. After that, a rate changes through "Set new rate" on the
            // edit page, which appends a dated entry instead of overwriting.
            Section::make('Starting rate')
                ->description('Optional. Recorded as the first entry in the rate history, effective from the date hired.')
                ->columns(2)
                ->visibleOn('create')
                ->schema([
                    Select::make('pay_basis')
                        ->label('Pay basis')
                        ->options(collect(PayBasis::cases())->mapWithKeys(fn (PayBasis $basis): array => [$basis->value => ucfirst($basis->value)])->all())
                        ->requiredWith('starting_rate'),

                    TextInput::make('starting_rate')
                        ->label('Rate')
                        ->rules(['nullable', 'numeric', 'gt:0']),
                ]),
        ]);
    }

    /**
     * @param  string  $example  a FORMAT MASK, never a real-looking number. The
     *                           first version used digits, one of which matched a
     *                           test employee's TIN exactly — and a placeholder
     *                           that looks like a number is indistinguishable from
     *                           a leaked one, to a reader and to the leak test.
     */
    private static function governmentNumber(string $field, string $label, string $example): TextInput
    {
        return self::writeOnly(
            TextInput::make($field)
                ->label($label)
                ->maxLength(255)
                ->placeholder($example)
                ->rules([
                    // The same check EmployeeService enforces, run here too so
                    // the message lands beside the field before anything saves.
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($field): void {
                        $problem = GovernmentIds::problemWith($field, $value);

                        if ($problem !== null) {
                            $fail($problem);
                        }
                    },
                ]),
        );
    }

    /**
     * Never render the stored value; only submit a value somebody typed.
     */
    private static function writeOnly(TextInput $input): TextInput
    {
        $field = $input->getName();

        return $input
            ->password()
            ->revealable(false)
            ->afterStateHydrated(fn (TextInput $component) => $component->state(null))
            ->dehydrated(fn (?string $state): bool => filled($state))
            ->helperText(fn (?Employee $record): ?string => $record === null
                ? null
                : (trim((string) $record->getAttribute($field)) !== '' ? 'On file.' : 'Not on file yet.'));
    }
}
