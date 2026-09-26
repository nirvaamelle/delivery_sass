# Logistics Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the Construction ERP into Logistics at the surface — renamed, client-account vocabulary, construction modules retired from view — and build the two shared pieces both logistics subsystems need.

**Architecture:** Nothing structural moves. `Project` is relabelled to *Client Account* in the interface only, with no column renamed and no migration of stored enum values. Construction resources are hidden from navigation by a config list, not deleted, so all 116 existing tests stay green. Two shared foundations are added because both the Warehouse and Transport plans depend on them: decimal division in `Money`, and the `warehouses` facility register that gate visits, pick tasks, manning and trip plans all reference.

**Tech Stack:** PHP 8.3, Laravel 13, Filament 4, MySQL 8.4, Pest 4, Larastan/PHPStan, bcmath.

**Spec:** `docs/superpowers/specs/2026-09-26-logistics-3pl-pivot-design.md` (committed at `9b43207`)

## Global Constraints

Every task's requirements implicitly include all of these.

- **Laragon binaries are not on PATH.** Every command in this plan assumes this export has been run first in the shell:
  `export PATH="/c/laragon/bin/php/php-8.3.30-Win32-vs16-x64:/c/laragon/bin/composer:/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin:$PATH"`
- **Tests are Pest**, not plain PHPUnit: `it('...', function () { ... })` with `expect()`. Feature tests get `RefreshDatabase` automatically via `tests/Pest.php`.
- **The suite runs on MySQL**, database `construction_test` (per `phpunit.xml`). Not SQLite — the numbering and gate tests need real InnoDB row locking. This database name is deliberately NOT renamed; `tests/Feature/Foundation/DeployPipelineTest.php` asserts on the staging name and `.github/workflows/ci.yml` provisions it.
- **Money and measurements are decimal strings**, never floats, never integer cents. `DECIMAL(18,4)` per `PLAN.md §4`. Use `App\Domain\Support\Money`. Assert with the `expectMoney()` helper from `tests/Pest.php`.
- **Every new Filament resource or page MUST get a rule in `config/access.php`.** `tests/Feature/Foundation/ScreenAccessTest.php` reads what Filament actually registers and fails on any screen without one. A screen with no rule is admin-only by design — fail closed.
- **Every new resource uses `AuthorizesScreenByRole`**; every new resource *page* uses `AuthorizesResourcePage`. Without the second, a screen hidden from the menu still answers its URL.
- **No column is renamed.** The `project_id` / "client account" seam is accepted per spec §D3.
- **PHPStan must stay clean:** `php vendor/bin/phpstan analyse`. Larastan infers `string` from cast columns, so every enum- or date-cast property needs an explicit `@property` docblock on the model.
- **Commit after every task.** Never bundle two tasks into one commit.

## File Structure

| File | Responsibility |
|---|---|
| `.env`, `.env.example` | `APP_NAME` — Filament reads `config('app.name')` for the brand |
| `app/Domain/Projects/ProjectPhase.php` | Adds a display layer; stored values untouched |
| `app/Filament/Resources/Projects/ProjectsResource.php` | Client-account vocabulary |
| `config/modules.php` | **New.** The single list of retired construction resources |
| `app/Filament/Concerns/RetiredModule.php` | **New.** Reads that list, hides from navigation only |
| `app/Domain/Support/Money.php` | Gains `divide()` — the one home for decimal arithmetic |
| `database/migrations/…_create_warehouses_table.php` | **New.** Facility register |
| `app/Models/Warehouse.php` | **New.** |
| `database/factories/WarehouseFactory.php` | **New.** |
| `app/Filament/Resources/Warehouses/**` | **New.** Resource, form, table, three pages |
| `config/access.php` | Screen rule for the new resource |

---

### Task 1: Rename the application and relabel client accounts

**Files:**
- Modify: `.env` (line 1), `.env.example` (line 1)
- Modify: `app/Domain/Projects/ProjectPhase.php`
- Modify: `app/Filament/Resources/Projects/ProjectsResource.php`
- Test: `tests/Feature/Foundation/LogisticsBrandingTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `ProjectPhase` implements `Filament\Support\Contracts\HasLabel` with `getLabel(): ?string`. This is a **new convention** in this codebase — no enum implemented it before — introduced so Filament tables and selects pick up account vocabulary automatically instead of each screen restating it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/LogisticsBrandingTest.php`:

```php
<?php

use App\Domain\Projects\ProjectPhase;
use App\Filament\Resources\Projects\ProjectsResource;

/*
|--------------------------------------------------------------------------
| The pivot, at the surface — spec §1 and §2 (D3)
|--------------------------------------------------------------------------
|
| The business is logistics; the schema is still construction's. The seam is
| deliberate (spec D3), so these tests pin BOTH halves: the vocabulary the user
| reads, and the stored values that must not move under it.
|
*/

it('labels each project phase in client-account language', function () {
    expect(ProjectPhase::ProjectAcquisition->getLabel())->toBe('Onboarding')
        ->and(ProjectPhase::PreConstruction->getLabel())->toBe('Go-Live')
        ->and(ProjectPhase::Construction->getLabel())->toBe('Operating')
        ->and(ProjectPhase::PostConstruction->getLabel())->toBe('Exit');
});

it('leaves the stored phase values untouched so no migration is needed', function () {
    expect(ProjectPhase::ProjectAcquisition->value)->toBe('project_acquisition')
        ->and(ProjectPhase::PreConstruction->value)->toBe('pre_construction')
        ->and(ProjectPhase::Construction->value)->toBe('construction')
        ->and(ProjectPhase::PostConstruction->value)->toBe('post_construction');
});

it('presents projects as client accounts', function () {
    expect(ProjectsResource::getModelLabel())->toBe('client account')
        ->and(ProjectsResource::getPluralModelLabel())->toBe('client accounts');
});

it('takes the panel brand from the application name', function () {
    expect(config('app.name'))->toBe('Logistics');
});
```

- [ ] **Step 2: Run it and watch it fail**

```
php artisan test tests/Feature/Foundation/LogisticsBrandingTest.php
```

Expected: FAIL — `Call to undefined method App\Domain\Projects\ProjectPhase::getLabel()`.

- [ ] **Step 3: Add the display layer to the enum**

In `app/Domain/Projects/ProjectPhase.php`, add the import and contract, and the method inside the enum:

```php
use Filament\Support\Contracts\HasLabel;

enum ProjectPhase: string implements HasLabel
{
    // ... existing cases unchanged ...

    /**
     * Account vocabulary over construction storage — spec §2, D3 wrinkle.
     *
     * The stored values stay as they are. Migrating them would rewrite a
     * column that ProjectService transitions on and that four existing test
     * files assert against, to buy nothing a label does not already buy.
     */
    public function getLabel(): ?string
    {
        return match ($this) {
            self::ProjectAcquisition => 'Onboarding',
            self::PreConstruction => 'Go-Live',
            self::Construction => 'Operating',
            self::PostConstruction => 'Exit',
        };
    }
}
```

- [ ] **Step 4: Relabel the resource**

In `app/Filament/Resources/Projects/ProjectsResource.php`, add these three properties alongside the existing `protected static` properties:

```php
    protected static ?string $modelLabel = 'client account';

    protected static ?string $pluralModelLabel = 'client accounts';

    protected static ?string $navigationLabel = 'Client accounts';
```

If a `$navigationLabel` property already exists on the class, replace its value rather than adding a second one.

- [ ] **Step 5: Rename the application**

Change line 1 of both `.env` and `.env.example` to:

```
APP_NAME=Logistics
```

Then clear the cached config so the running server picks it up:

```
php artisan config:clear
```

- [ ] **Step 6: Run the test and watch it pass**

```
php artisan test tests/Feature/Foundation/LogisticsBrandingTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 7: Prove nothing else broke**

```
php artisan test
php vendor/bin/phpstan analyse
```

Expected: the full suite green (116 files plus this one), PHPStan clean. If any existing test asserts on the phase's *display*, it will surface here — fix by asserting on `->value`, never by weakening the new label.

- [ ] **Step 8: Commit**

```bash
git add .env.example app/Domain/Projects/ProjectPhase.php \
  app/Filament/Resources/Projects/ProjectsResource.php \
  tests/Feature/Foundation/LogisticsBrandingTest.php
git commit -m "rename: Logistics, and the account vocabulary over construction storage"
```

Note `.env` is gitignored and is deliberately not staged.

---

### Task 2: Retire the construction interface

**Files:**
- Create: `config/modules.php`
- Create: `app/Filament/Concerns/RetiredModule.php`
- Modify: the 15 retired resource classes listed below (one `use` line plus one trait line each)
- Test: `tests/Feature/Foundation/RetiredModulesTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Filament\Concerns\RetiredModule`, a trait providing `public static function shouldRegisterNavigation(): bool`. Reads `config('modules.retired', [])`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Foundation/RetiredModulesTest.php`:

```php
<?php

use App\Filament\Resources\Projects\ProjectsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\Warranties\WarrantiesResource;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Hidden, not deleted — spec §8
|--------------------------------------------------------------------------
|
| The construction modules keep their code, their tables and their tests. They
| lose the menu. The third test is the important one: hiding is a PRESENTATION
| decision and must never masquerade as an access control, because a reader who
| believes a hidden screen is a secured screen will stop securing it.
|
*/

it('hides a retired construction module from the navigation', function () {
    expect(PunchlistsResource::shouldRegisterNavigation())->toBeFalse()
        ->and(WarrantiesResource::shouldRegisterNavigation())->toBeFalse();
});

it('keeps a retained module in the navigation', function () {
    expect(ProjectsResource::shouldRegisterNavigation())->toBeTrue();
});

it('still answers a retired module url, because hiding is not access control', function () {
    actingAs(panelUser());

    get(PunchlistsResource::getUrl('index'))->assertSuccessful();
});

it('names every retired module as a real resource class', function () {
    foreach (config('modules.retired') as $resource) {
        expect(class_exists($resource))->toBeTrue("{$resource} does not exist");
    }
});
```

- [ ] **Step 2: Run it and watch it fail**

```
php artisan test tests/Feature/Foundation/RetiredModulesTest.php
```

Expected: FAIL — `shouldRegisterNavigation()` returns `true` for Punchlists (Filament's default), and `config('modules.retired')` is null.

- [ ] **Step 3: Write the config**

Create `config/modules.php`:

```php
<?php

use App\Filament\Resources\Accomplishments\AccomplishmentsResource;
use App\Filament\Resources\BackCharges\BackChargesResource;
use App\Filament\Resources\CloseOutChecklists\CloseOutChecklistsResource;
use App\Filament\Resources\Demobilizations\DemobilizationsResource;
use App\Filament\Resources\FinalAccounts\FinalAccountsResource;
use App\Filament\Resources\Mobilizations\MobilizationsResource;
use App\Filament\Resources\Permits\PermitsResource;
use App\Filament\Resources\Punchlists\PunchlistsResource;
use App\Filament\Resources\ReceivingReports\ReceivingReportsResource;
use App\Filament\Resources\RetentionReleases\RetentionReleasesResource;
use App\Filament\Resources\StockCards\StockCardsResource;
use App\Filament\Resources\Subcontracts\SubcontractsResource;
use App\Filament\Resources\ThreeWayMatches\ThreeWayMatchesResource;
use App\Filament\Resources\TurnoverPacks\TurnoverPacksResource;
use App\Filament\Resources\Warranties\WarrantiesResource;

/*
|--------------------------------------------------------------------------
| Retired modules
|--------------------------------------------------------------------------
|
| The business pivoted to third-party logistics; these screens describe a
| construction firm's work. They are hidden from the navigation and nothing
| else — spec §8. Code, tables, routes and tests all stay, and re-enabling a
| module is deleting one line from this list.
|
| Deliberately NOT an access control. Access stays with ScreenAccess and
| config/access.php, which every one of these resources still has a rule in.
|
*/

return [
    'retired' => [
        AccomplishmentsResource::class,
        BackChargesResource::class,
        CloseOutChecklistsResource::class,
        DemobilizationsResource::class,
        FinalAccountsResource::class,
        MobilizationsResource::class,
        PermitsResource::class,
        PunchlistsResource::class,
        ReceivingReportsResource::class,
        RetentionReleasesResource::class,
        StockCardsResource::class,
        SubcontractsResource::class,
        ThreeWayMatchesResource::class,
        TurnoverPacksResource::class,
        WarrantiesResource::class,
    ],
];
```

All fifteen class names above were verified against `app/Filament/Resources/` when this plan was written; the fourth test re-checks them so a later rename cannot rot this list silently. Note the spec's §8 prose names sixteen modules — Substantial Completions has **no** Filament resource, so it has no line here.

- [ ] **Step 4: Write the trait**

Create `app/Filament/Concerns/RetiredModule.php`:

```php
<?php

namespace App\Filament\Concerns;

/**
 * A module the pivot retired — spec §8.
 *
 * Navigation only. `canViewAny()` still decides who may open the screen, and
 * the route still resolves, so a bookmark from before the pivot does not
 * 404 and nobody mistakes a hidden menu entry for a secured one.
 */
trait RetiredModule
{
    public static function shouldRegisterNavigation(): bool
    {
        $retired = config('modules.retired', []);

        return ! (is_array($retired) && in_array(static::class, $retired, true));
    }
}
```

- [ ] **Step 5: Apply the trait to each retired resource**

For each of the 15 classes named in `config/modules.php`, add the import and the trait next to the existing `AuthorizesScreenByRole`. For example, in `app/Filament/Resources/Punchlists/PunchlistsResource.php`:

```php
use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Concerns\RetiredModule;

class PunchlistsResource extends Resource
{
    use AuthorizesScreenByRole;
    use RetiredModule;
```

- [ ] **Step 6: Run the test and watch it pass**

```
php artisan test tests/Feature/Foundation/RetiredModulesTest.php
```

Expected: PASS, 4 tests.

- [ ] **Step 7: Prove the access rules survived**

```
php artisan test tests/Feature/Foundation/ScreenAccessTest.php
php artisan test
```

Expected: both green. The retired resources are still registered with the panel, so they still need — and still have — their `config/access.php` rules. If `ScreenAccessTest` fails here, a rule was removed that should not have been.

- [ ] **Step 8: Commit**

```bash
git add config/modules.php app/Filament/Concerns/RetiredModule.php \
  app/Filament/Resources tests/Feature/Foundation/RetiredModulesTest.php
git commit -m "pivot: retire the construction screens from the menu, not from the build"
```

---

### Task 3: Decimal division

**Files:**
- Modify: `app/Domain/Support/Money.php`
- Test: `tests/Unit/MoneyDivisionTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `Money::divide(string $a, string $b, int $scale = self::SCALE): string`. Throws `InvalidArgumentException` on a zero divisor or a non-decimal argument. **Both the Warehouse plan (picking productivity, accuracy) and the Transport plan (km/L) depend on this signature.**

This is a `tests/Unit` test, not Feature — it touches no database, and `tests/Pest.php` only binds `RefreshDatabase` to `Feature`.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/MoneyDivisionTest.php`:

```php
<?php

use App\Domain\Support\Money;

/*
|--------------------------------------------------------------------------
| Division arrives with logistics — spec §6.3
|--------------------------------------------------------------------------
|
| Money has summed, multiplied and rounded since the first migration; nothing
| had needed to divide. km/L, lines per hour and pick accuracy are all
| divisions, and all three would otherwise be written as floats in three
| different services. One home, one rounding rule.
|
*/

it('divides decimal strings at the requested scale', function () {
    expect(Money::divide('250', '45', 3))->toBe('5.556');
});

it('divides at the money scale by default', function () {
    expect(Money::divide('100', '8'))->toBe('12.5000');
});

it('divides negative values without rounding toward zero', function () {
    expect(Money::divide('-250', '45', 3))->toBe('-5.556');
});

it('refuses a zero divisor rather than returning infinity', function () {
    Money::divide('250', '0', 3);
})->throws(InvalidArgumentException::class);

it('refuses a float dressed as a divisor', function () {
    Money::divide('250', 'abc', 3);
})->throws(InvalidArgumentException::class);
```

Note `250 / 45 = 5.5555…`, which rounds half-up at 3 places to `5.556` — the figure from the spec. `-250 / 45` must give `-5.556`, not `-5.555`; `Money::round()` already handles the negative case and this test pins it.

- [ ] **Step 2: Run it and watch it fail**

```
php artisan test tests/Unit/MoneyDivisionTest.php
```

Expected: FAIL — `Call to undefined method App\Domain\Support\Money::divide()`.

- [ ] **Step 3: Implement it**

Add to `app/Domain/Support/Money.php`, after `multiply()`:

```php
    /**
     * Divide two decimal strings, rounded half up to the requested scale.
     *
     * Carried at WORKING_SCALE first for the same reason multiply is: the
     * rounding decision is made once, at the end, on a full-precision quotient.
     *
     * @throws InvalidArgumentException when either value is not a decimal
     *                                  string, or the divisor is zero
     */
    public static function divide(string $a, string $b, int $scale = self::SCALE): string
    {
        self::assertDecimal($a);
        self::assertDecimal($b);

        if (self::isZero($b)) {
            throw new InvalidArgumentException(
                'Cannot divide by zero. A trip with no litres, a pick task with no lines and a period with no hours each have no rate — report no figure rather than one the arithmetic invented.'
            );
        }

        return self::round(bcdiv($a, $b, self::WORKING_SCALE), $scale);
    }
```

- [ ] **Step 4: Run the test and watch it pass**

```
php artisan test tests/Unit/MoneyDivisionTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 5: Prove nothing else broke**

```
php artisan test
php vendor/bin/phpstan analyse
```

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Support/Money.php tests/Unit/MoneyDivisionTest.php
git commit -m "money: division, because km/L and lines-per-hour are not float work"
```

---

### Task 4: The warehouse facility register

**Files:**
- Create: `database/migrations/2026_09_26_000300_create_warehouses_table.php`
- Create: `app/Models/Warehouse.php`
- Create: `database/factories/WarehouseFactory.php`
- Create: `app/Filament/Resources/Warehouses/WarehousesResource.php`
- Create: `app/Filament/Resources/Warehouses/Schemas/WarehouseForm.php`
- Create: `app/Filament/Resources/Warehouses/Tables/WarehousesTable.php`
- Create: `app/Filament/Resources/Warehouses/Pages/ListWarehouses.php`
- Create: `app/Filament/Resources/Warehouses/Pages/CreateWarehouse.php`
- Create: `app/Filament/Resources/Warehouses/Pages/EditWarehouse.php`
- Modify: `config/access.php`
- Modify: `tests/Browser/console.spec.js`
- Test: `tests/Feature/Warehouse/WarehouseRegisterTest.php` (create)

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Models\Warehouse` with `organization_id`, `code`, `name`, `address`, `is_active` and a `belongsTo(Organization::class)` relation; `WarehouseFactory` with an `inactive()` state. **Both later plans reference `warehouse_id`** — gate visits, pick tasks, manning allocations and trip plans all hang off this table, which is why it lands here rather than in either subsystem.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Warehouse/WarehouseRegisterTest.php`:

```php
<?php

use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Database\QueryException;

/*
|--------------------------------------------------------------------------
| The facility register — spec §4.1
|--------------------------------------------------------------------------
|
| A register, not a hierarchy. Bins and directed putaway are out of scope
| (spec §10) until a client needs them; what every other logistics table needs
| today is a stable id to hang off.
|
*/

it('registers a warehouse', function () {
    $warehouse = Warehouse::factory()->create([
        'code' => 'WH-MNL-01',
        'name' => 'Manila Distribution Centre',
    ]);

    expect($warehouse->code)->toBe('WH-MNL-01')
        ->and($warehouse->name)->toBe('Manila Distribution Centre')
        ->and($warehouse->is_active)->toBeTrue();
});

it('refuses two warehouses with the same code in one organization', function () {
    $organization = Organization::factory()->create();

    Warehouse::factory()->for($organization)->create(['code' => 'WH-MNL-01']);
    Warehouse::factory()->for($organization)->create(['code' => 'WH-MNL-01']);
})->throws(QueryException::class);

it('lets two organizations each use the same code', function () {
    Warehouse::factory()->for(Organization::factory()->create())->create(['code' => 'WH-01']);
    Warehouse::factory()->for(Organization::factory()->create())->create(['code' => 'WH-01']);

    expect(Warehouse::query()->where('code', 'WH-01')->count())->toBe(2);
});

it('belongs to an organization', function () {
    $warehouse = Warehouse::factory()->create();

    expect($warehouse->organization)->toBeInstanceOf(Organization::class);
});

it('can be deactivated without being deleted', function () {
    $warehouse = Warehouse::factory()->inactive()->create();

    expect($warehouse->is_active)->toBeFalse()
        ->and(Warehouse::query()->count())->toBe(1);
});
```

- [ ] **Step 2: Run it and watch it fail**

```
php artisan test tests/Feature/Warehouse/WarehouseRegisterTest.php
```

Expected: FAIL — `Class "App\Models\Warehouse" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_09_26_000300_create_warehouses_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            /*
             * The code is what appears on a delivery receipt and what a
             * dispatcher says on the phone. Unique per company, not globally:
             * two organizations each calling their first site WH-01 is normal.
             */
            $table->string('code', 32);
            $table->string('name');
            $table->text('address')->nullable();

            /*
             * Closed sites are deactivated, never deleted — gate visits, pick
             * tasks and trip plans point here, and history has to keep
             * resolving after a site closes.
             */
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'code'], 'warehouses_org_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
```

- [ ] **Step 4: Write the model and factory**

Create `app/Models/Warehouse.php`:

```php
<?php

namespace App\Models;

use Database\Factories\WarehouseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site where goods are held — spec §4.1.
 *
 * @property bool $is_active
 */
#[Fillable(['organization_id', 'code', 'name', 'address', 'is_active'])]
class Warehouse extends Model
{
    /** @use HasFactory<WarehouseFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
```

Create `database/factories/WarehouseFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => 'WH-'.$this->faker->unique()->numberBetween(100, 999),
            'name' => $this->faker->company().' Distribution Centre',
            'address' => $this->faker->address(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
```

- [ ] **Step 5: Run the migration and the test**

```
php artisan migrate
php artisan test tests/Feature/Warehouse/WarehouseRegisterTest.php
```

Expected: PASS, 5 tests.

- [ ] **Step 6: Write the Filament resource**

Create `app/Filament/Resources/Warehouses/Schemas/WarehouseForm.php`:

```php
<?php

namespace App\Filament\Resources\Warehouses\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class WarehouseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('organization_id')
                ->relationship('organization', 'name')
                ->required(),
            TextInput::make('code')
                ->required()
                ->maxLength(32)
                ->helperText('Unique within the company. Appears on delivery receipts.'),
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            Textarea::make('address')
                ->rows(3),
            Toggle::make('is_active')
                ->default(true)
                ->helperText('A closed site is deactivated, never deleted — history points here.'),
        ]);
    }
}
```

Create `app/Filament/Resources/Warehouses/Tables/WarehousesTable.php`:

```php
<?php

namespace App\Filament\Resources\Warehouses\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class WarehousesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable(),
                TextColumn::make('organization.name')->label('Company')->sortable(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->defaultSort('code')
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
```

Create `app/Filament/Resources/Warehouses/WarehousesResource.php`:

```php
<?php

namespace App\Filament\Resources\Warehouses;

use App\Filament\Concerns\AuthorizesScreenByRole;
use App\Filament\Resources\Warehouses\Pages\CreateWarehouse;
use App\Filament\Resources\Warehouses\Pages\EditWarehouse;
use App\Filament\Resources\Warehouses\Pages\ListWarehouses;
use App\Filament\Resources\Warehouses\Schemas\WarehouseForm;
use App\Filament\Resources\Warehouses\Tables\WarehousesTable;
use App\Models\Warehouse;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * The facility register — spec §4.1. Every other logistics table hangs off it.
 */
class WarehousesResource extends Resource
{
    use AuthorizesScreenByRole;

    protected static ?string $model = Warehouse::class;

    protected static ?string $recordTitleAttribute = 'code';

    protected static ?string $navigationLabel = 'Warehouses';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    public static function form(Schema $schema): Schema
    {
        return WarehouseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WarehousesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWarehouses::route('/'),
            'create' => CreateWarehouse::route('/create'),
            'edit' => EditWarehouse::route('/{record}/edit'),
        ];
    }
}
```

Create the three pages. `app/Filament/Resources/Warehouses/Pages/ListWarehouses.php`:

```php
<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warehouses\WarehousesResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWarehouses extends ListRecords
{
    use AuthorizesResourcePage;

    protected static string $resource = WarehousesResource::class;

    /**
     * @return array<int, \Filament\Actions\Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
```

`app/Filament/Resources/Warehouses/Pages/CreateWarehouse.php`:

```php
<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warehouses\WarehousesResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWarehouse extends CreateRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = WarehousesResource::class;
}
```

`app/Filament/Resources/Warehouses/Pages/EditWarehouse.php`:

```php
<?php

namespace App\Filament\Resources\Warehouses\Pages;

use App\Filament\Concerns\AuthorizesResourcePage;
use App\Filament\Resources\Warehouses\WarehousesResource;
use Filament\Resources\Pages\EditRecord;

class EditWarehouse extends EditRecord
{
    use AuthorizesResourcePage;

    protected static string $resource = WarehousesResource::class;
}
```

Open `app/Filament/Resources/CostCodes/Pages/ListCostCodes.php` and match its exact shape if any import above differs — that file is the working reference for this Filament version.

- [ ] **Step 7: Give the screen an access rule**

In `config/access.php`, add the import and an entry in the `screens` array. The roles are exactly those on the existing `StockCardsResource` entry, because a facility register is read by the same people:

```php
use App\Filament\Resources\Warehouses\WarehousesResource;

// ... inside the 'screens' array:
    WarehousesResource::class => ['procurement-head', 'project-manager', 'finance-manager', 'storekeeper'],
```

`admin` is deliberately absent: it is a super role in `config/access.php` and passes every screen already. The twelve roles this system knows are `admin`, `managing-director`, `finance-manager`, `procurement-head`, `project-manager`, `site-engineer`, `storekeeper`, `hr-manager`, `timekeeper` and `foreman` — do not invent a new one here.

- [ ] **Step 8: Prove the screen is registered, ruled and reachable**

```
php artisan test tests/Feature/Foundation/ScreenAccessTest.php
php artisan test
php vendor/bin/phpstan analyse
```

Expected: all green. `ScreenAccessTest` proves the new resource has a rule and that an administrator can open its index — it discovers the resource automatically, so no test list needs updating.

- [ ] **Step 9: Add the screen to the browser console gate**

In `tests/Browser/console.spec.js`, append this test. It uses that file's own `watch()` helper, which collects console errors, console warnings, page errors and any HTTP response of 400 or worse:

```js
test('warehouse register has a clean console', async ({ page }) => {
  const problems = watch(page);

  await page.goto('/admin/warehouses');
  await page.waitForLoadState('networkidle');

  expect(problems, problems.join('\n')).toEqual([]);
});
```

The bar is zero errors **and** zero warnings — a 404 asset counts, an Alpine or Livewire warning counts. Do not relax it.

- [ ] **Step 10: Run the console gate**

```
npm run test:console
```

Expected: PASS, 53 tests (the 52 that exist plus the new one). The dev server is booted and torn down by `playwright.config.js`; nothing needs to be running beforehand.

- [ ] **Step 11: Commit**

```bash
git add database/migrations database/factories/WarehouseFactory.php \
  app/Models/Warehouse.php app/Filament/Resources/Warehouses \
  config/access.php tests/Feature/Warehouse tests/Browser/console.spec.js
git commit -m "warehouse: the facility register both logistics subsystems hang off"
```

---

## Done when

- `php artisan test` is green, with 5 new test files added and none of the existing 116 modified except where a phase label forced it.
- `php vendor/bin/phpstan analyse` is clean.
- `npm run test:console` passes with the new screen included.
- The panel reads **Logistics**, projects read **Client accounts**, and the construction modules are gone from the menu while their URLs still resolve for an administrator.

## What this plan deliberately does not do

- Does not create `config/logistics.php`. Truck categories and the fuel variance tolerance are Transport's, and belong in the task that first reads them.
- Does not touch `.env.staging.example`, `DEPLOY.md`, `RUNBOOK.md` or `ops/`. They name `construction_staging`, which `tests/Feature/Foundation/DeployPipelineTest.php` asserts on.
- Does not migrate `ProjectPhase`'s stored values (spec §10).
- Does not rename the `construction_test` database.
