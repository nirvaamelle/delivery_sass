# Logistics — 3PL pivot design

> Status: **approved**, 2026-09-26. Supersedes nothing; extends the system
> described in `PLAN.md`. Construction scope is retained in code and retired
> from the interface — see §8.

## 1. What changes, and why

The business this system serves is now third-party logistics: warehousing and
trucking for external clients. It is not a construction firm that happens to
own a warehouse.

That single fact decides every question below, because the two businesses
disagree about what a warehouse *is*. Construction inventory is material you
own and consume, and its cost belongs to a project. Third-party warehouse
inventory is a client's goods held in your custody: you never own it, you never
consume it, and what it generates is not cost but **billable charges**.

The organising rule of `PLAN.md §1` survives the pivot unchanged:

> Every process ends in the same place: the project cost ledger.

What changes is the meaning of the anchor, not the existence of it.

## 2. Decisions taken

| # | Question | Decision |
|---|---|---|
| D1 | Direction | Pivot. The product is a 3PL system. |
| D2 | Existing construction modules | Hidden, not deleted. Code and tables stay. |
| D3 | Ledger anchor | `Project` is reinterpreted as **Client Account**. |
| D4 | TAT clock | Truck dwell: gate-in to gate-out. |
| D5 | Picking metrics | Lines per hour; accuracy from an explicit check step. |
| D6 | Trip capacity | `min(drivers, helpers, trucks)`. |
| D7 | Fuel capture | Per trip **and** per refuel, reconciled per period. |

### D3 in full

A 3PL engagement — "SM Retail, Warehouse A, storage and distribution" —
becomes a row in `projects`, relabelled *Client Account* throughout the
interface. `CostCode` becomes the service/charge code.

The alternatives were a second anchor (`client_id` alongside `project_id`) and
a polymorphic ledger subject. Both were rejected for the same reason: they
split the spine. `project_cost_ledger.project_id` is `NOT NULL` with a foreign
key, the table is append-only under database triggers, and a row-level
`ProjectScope` global scope sits on top of it. Touching that costs the
guarantees the system exists to provide, before a single logistics feature is
built.

The cost of D3 is a permanent vocabulary seam: the database says `project_id`
while the business says *account*. This is accepted deliberately and is papered
over only at the interface. No migration renames a column.

### D3 wrinkle — `ProjectPhase`

`App\Domain\Projects\ProjectPhase` is a stored DB enum with four construction
phases and no display layer. A client account's lifecycle is different.

**Resolution:** add a `label()` method to the enum and render labels through it
— `ProjectAcquisition` to "Onboarding", `PreConstruction` to "Go-Live",
`Construction` to "Operating", `PostConstruction` to "Exit". The stored values
do not change, `ProjectService`'s transitions do not change, and no existing
test changes. Migrating the underlying values is a later decision, not a
prerequisite.

## 3. Domain layout

Two new domains, flat, matching the existing convention:

```
app/Domain/Warehouse/     app/Domain/Transport/
```

They do not nest under a `Logistics/` parent. The codebase does not nest
domains, and the two are independent: a warehouse-only client has no trips, a
trucking-only client has no pick tasks.

## 4. Warehouse

### 4.1 Facility

`warehouses` — `organization_id`, `code`, `name`, `address`, `is_active`.

A register, not a hierarchy. Bin and location topology is explicitly out of
scope (§10).

### 4.2 Custody inventory

`inventory_items` and `inventory_movements`, **separate from** the existing
`stock_cards` and `stock_movements`, which remain construction-shaped and
hidden. The separation is the point: merging them would force one table to mean
both "material we consume, costed to a project" and "goods we hold, owned by a
client".

`inventory_items` — `project_id` (account), `warehouse_id`, `sku`,
`description`, `uom`, `is_active`. Unique on `(project_id, warehouse_id, sku)`.

`inventory_movements` — `inventory_item_id`, `type`, `quantity` (signed,
`decimal(18,4)`), `lot_reference`, `location_reference`,
`source_document_type`, `source_document_id`, `moved_at`, `moved_by_user_id`,
`remarks`.

`InventoryMovementType`: `Receipt`, `Release`, `Adjustment`, `TransferIn`,
`TransferOut`.

Two rules copied deliberately from `StockMovement`, which already earned them:

1. **No balance column.** On-hand is the sum of movements, computed with
   `Money::sum` over decimal strings. A stored balance is a second source of
   truth that drifts.
2. **Movements are immutable.** `updating` throws `DomainException`. A miscount
   is corrected by an adjustment that states itself, not by an edit that hides
   itself.

### 4.3 Turn Around Time

`gate_visits` — `warehouse_id`, `project_id` (account), `direction`
(`Inbound` or `Outbound`), `plate_number`, `carrier_name`, `driver_name`,
`reference_document`, `gate_in_at`, `gate_out_at`, `recorded_by_user_id`,
`remarks`.

TAT is `gate_out_at` minus `gate_in_at`, in minutes, derived — never stored as
a duration column, for the same reason a balance is never stored.

Invariants, enforced in `GateVisitService`:

- `gate_out_at` may not precede `gate_in_at`.
- A visit already closed may not be closed again.
- An open visit may not be deleted; it is closed or cancelled with a reason.

Reporting: average, median and worst dwell, grouped by direction, warehouse,
client account and period.

### 4.4 Picking productivity and accuracy

`pick_tasks` — `warehouse_id`, `project_id`, `order_reference`,
`picker_employee_id`, `lines_count`, `started_at`, `ended_at`, `status`.

`pick_checks` — `pick_task_id`, `checker_employee_id`, `lines_checked`,
`lines_wrong`, `checked_at`, `remarks`.

Formulas, both computed and never stored:

```
productivity (lines/hour) = lines_count / ((ended_at - started_at) in hours)
accuracy (%)              = (lines_checked - lines_wrong) / lines_checked * 100
```

Guards: `ended_at` after `started_at`; a task of zero duration or zero lines
yields no productivity figure rather than a division by zero;
`lines_wrong <= lines_checked`.

Gate: `PickTaskHasPassedCheck` — a pick task cannot reach `Dispatched` without
a `pick_check`. A checker may not be the picker.

### 4.5 Charges

`tariffs` — `project_id` (account), `charge_code`, `name`, `basis`, `rate`
(`decimal(18,4)`), `effective_from`, `effective_to`, `is_active`.

`TariffBasis`: `PerPalletPerDay`, `PerLine`, `PerTrip`, `PerKg`, `Flat`.

`charges` — `project_id`, `warehouse_id`, `tariff_id`, `charge_code` and `rate`
**snapshotted at creation**, `source_document_type` and `source_document_id`
(morph: `GateVisit`, `PickTask`, `Trip`, `InventoryMovement`), `quantity`,
`amount`, `charged_on`, `status`, `charge_run_id`.

The snapshot rule follows `project_cost_ledger`'s own: editing a tariff must
never silently restate last quarter's charges.

Overlapping active tariffs for one account and charge code are rejected at
write time — an ambiguous rate is a billing dispute.

## 5. How charges become revenue

**Charges do not post to the ledger.** `RevenuePoster` accepts a
`SalesInvoice`, by explicit policy documented in that class: revenue is
recognised at the invoice, not at the claim and not at the collection. A charge
is a claim.

The construction chain is `Accomplishment` to `Billing` to `SalesInvoice` to
`RevenuePoster` to ledger. Logistics reuses it exactly, substituting one
document:

```
Charge (many)
  -> ChargeRun      (charges measured over a period: the Accomplishment analogue)
  -> Billing        (one BillingLine per charge_code, line_key = charge_code)
  -> SalesInvoice
  -> RevenuePoster  (existing, unchanged)
  -> project_cost_ledger as LedgerCategory::Revenue
```

`charge_runs` — `project_id`, `number`, `period_start`, `period_end`, `status`,
`total_amount`, `billing_id`.

Consequences, all of them wanted:

- Per-client P&L works with **no new reporting code**; the existing
  `ProjectProfitAndLoss` page reads the same ledger.
- Cutoff enforcement, immutability and reversal come free, because
  `LedgerPoster` remains the only writer of the ledger.
- A charge already attached to a closed run cannot be edited or re-run; a
  correction is a negative charge in the next run.

Cost postings are unchanged in shape: fuel, repairs and overhead reach the
ledger as they do today.

## 6. Transport

### 6.1 Manning allocation

`Employee.position` is free text and cannot carry an allocation rule, so crew
role is pinned in its own table.

`crew_members` — `employee_id`, `role` (`Driver` or `Helper`),
`licence_number`, `licence_expiry`, `is_active`. Unique on `employee_id`.

`manning_allocations` — `allocation_date`, `warehouse_id`, `employee_id`,
`crew_role`, `status`, `remarks`. Unique on `(allocation_date, employee_id)`:
one person cannot be rostered twice on one day.

`ManningStatus`: `Available`, `Assigned`, `Absent`, `OnLeave`.

### 6.2 Trips planning

`trip_plans` — `plan_date`, `warehouse_id`, `available_drivers`,
`available_helpers`, `available_trucks`, `planned_trips`, `bottleneck`,
`status`, `published_at`. Unique on `(plan_date, warehouse_id)`.

The formula, from D6:

```
planned_trips = min(available_drivers, available_helpers, available_trucks)
bottleneck    = whichever of the three produced that minimum
```

10 drivers, 8 helpers and 12 trucks plans **8** trips and names *helpers* as
the bottleneck. Naming the bottleneck is the point of the screen: a plan that
says only "8" does not tell a dispatcher what to fix.

Counts are derived, not typed: drivers and helpers from `manning_allocations`
at `Available` for that date; trucks from the existing `equipment` register
filtered to a truck category and `EquipmentStatus::Available`, minus those
already bound to a trip on that date.

`equipment.category` is a nullable free-text column, so the truck filter reads
its accepted values from `config('logistics.truck_categories')` rather than
hard-coding a magic string in a query. A fleet that spells it "Truck" or
"10-wheeler" is a config change, not a code change.

`trips` — `trip_plan_id`, `trip_number`, `equipment_id` (truck),
`driver_employee_id`, `helper_employee_id`, `project_id` (account), `origin`,
`destination`, `dispatched_at`, `returned_at`, `status`, `remarks`.

`TripStatus`: `Planned`, `Dispatched`, `Completed`, `Cancelled`.

The rule — one truck, one driver, one helper — is enforced twice, deliberately:
unique indexes on `(equipment_id, plan_date)`, `(driver_employee_id,
plan_date)` and `(helper_employee_id, plan_date)`, **and** preconditions in the
domain. `PLAN.md §5` is explicit that a control existing only in the UI is not
a control, because a queued job or a CSV import never opens a form.

Gates on `Trip` reaching `Dispatched`:

- `TripHasCompleteCrew` — a truck, a driver and a helper are all bound.
- `TripCrewIsAvailable` — all three are `Available` on the plan date.
- `TripDriverLicenceIsValid` — `licence_expiry` is not past the plan date.

### 6.3 Fuel consumption

Per trip, per D7:

`trip_fuel_logs` — `trip_id`, `km_run` (`decimal(10,2)`), `liters_consumed`
(`decimal(10,2)`), `km_per_liter` (`decimal(10,3)`), `odometer_start`,
`odometer_end`, `recorded_by_user_id`. Unique on `trip_id`.

```
km_per_liter = km_run / liters_consumed        # 250 / 45 = 5.556
```

`App\Domain\Support\Money` already provides `sum`, `multiply`, `round` and
comparison helpers over decimal strings, but **no division** — nothing in the
system has needed it until now. Division arrives with this work, so it is added
there as `Money::divide(string $a, string $b, int $scale)`, throwing on a zero
divisor, and km/L, picking productivity and accuracy all use it. Putting it
anywhere else would start a second home for decimal arithmetic.

Computed at 3 decimal places, consistent with the codebase's refusal to do
money or measurement arithmetic in floats. `liters_consumed` must
be greater than zero; a zero-litre log is rejected rather than stored as
infinity. `km_per_liter` is stored because it is reported and ranked across
many rows, and it is recomputed on every write — it is a materialised
derivation, not an input, and it is never editable directly.

Per refuel:

`fuel_purchases` — `equipment_id`, `vendor_id`, `purchased_at`, `liters`,
`unit_price`, `amount`, `odometer_reading`, `reference`, `project_id`,
`cost_code_id`, `recorded_by_user_id`. Cost reaches the ledger through the
existing `EquipmentCost` path with `EquipmentCostType::Fuel`, unchanged.

Reconciliation, per truck per period:

`fuel_reconciliations` — `equipment_id`, `period_start`, `period_end`,
`trip_liters`, `purchased_liters`, `variance_liters`, `variance_percent`,
`status`, `explained_by_user_id`, `explanation`, `explained_at`.

```
variance_liters = purchased_liters - trip_liters
```

An unexplained variance beyond a configured tolerance blocks the period close.
This mirrors `OpexVarianceExplanation` and `PayrollVarianceExplanation`, which
already establish "an unexplained variance blocks the close" as how this system
thinks. Unexplained fuel variance is pilferage, and it is the reason the double
capture in D7 was chosen over either source alone.

## 7. Filament surface

New resources, in two navigation groups:

**Warehouse** (existing group, extended): Warehouses, Inventory Items,
Inventory Movements, Gate Visits, Pick Tasks, Tariffs, Charges, Charge Runs.

**Transport** (new group): Crew Members, Manning Allocation, Trip Plans, Trips,
Trip Fuel Logs, Fuel Purchases, Fuel Reconciliations.

Three report pages, following `ProjectProfitAndLoss`'s shape: **TAT
Performance**, **Picking Performance**, **Fleet Fuel Efficiency**.

Dashboard widgets: today's planned trips with bottleneck, average TAT this week
by direction, picking accuracy this week, worst three trucks by km/L.

## 8. Retiring the construction interface

Hidden from navigation, code and tables untouched: Punchlists, Warranties,
Permits, Mobilizations, Retention Releases, Accomplishments, Subcontracts,
Three-Way Matches, Close-out Checklists, Turnover Packs, Demobilizations,
Back Charges, Final Accounts, Substantial Completions, Stock Cards, Receiving
Reports.

Retained and repurposed: Client Accounts (`projects`), Contracts, Billings,
Sales Invoices, AR Escalations, the whole HRIS and payroll chain, Vendors,
Procurement, OPEX, Equipment, Cost Codes, Approvals, Users.

Mechanism: one `config/modules.php` array of hidden resource classes, read by a
`HiddenWhenRetired` concern that overrides `shouldRegisterNavigation()`.
Re-enabling a module is deleting a line. Routes are **not** removed — a
bookmarked URL still resolves, because hiding is a presentation decision and
must not masquerade as an access control. Access control remains
`AuthorizesScreenByRole`.

## 9. Testing

Feature tests in `tests/Feature/Warehouse/` and `tests/Feature/Transport/`,
mirroring the existing layout, with a factory per model.

Each of these is a named test, because each is a rule someone will eventually
try to break:

- On-hand equals the sum of movements; a movement cannot be edited.
- Gate-out before gate-in is refused; a closed visit cannot close twice.
- TAT and productivity return no figure — not a crash — on zero duration.
- Accuracy math; `lines_wrong` greater than `lines_checked` refused.
- A pick task cannot dispatch without a check; a picker cannot check their own.
- `min()` capacity across all three resources, and the bottleneck named
  correctly for each of the three, including ties.
- A driver, helper or truck cannot be bound to two trips on one date.
- An expired licence blocks dispatch.
- 250 divided by 45 is 5.556; zero litres refused.
- Reconciliation variance math; an unexplained variance blocks the close.
- Overlapping active tariffs refused.
- A charge run produces one billing line per charge code, and the invoice posts
  revenue once.
- A posted charge run cannot be edited or re-run.

New screens are added to `tests/Browser/console.spec.js`. The repo's standard is
that every screen proves a clean browser console; the new work is held to it.

## 10. Out of scope

Named so they are decisions rather than omissions:

- Bin and location topology, and directed putaway. `location_reference` is a
  free string until a client needs more.
- Route optimisation and mapping. Trips carry origin and destination text.
- Barcode and scanner hardware integration.
- Client-facing portal. Clients see output through documents, not logins.
- Telematics or GPS fuel integration. Litres and kilometres are entered.
- Multi-leg trips. One trip is one truck, one crew, one day, per D6.
- Migrating `ProjectPhase`'s stored values — labels only, per §2.

## 11. Defaults chosen where the client has not decided

These are live defaults, not open questions. Each is cheap to change and is
recorded in `DECISIONS-PENDING.md`.

| Item | Default |
|---|---|
| Fuel variance tolerance | 5% of purchased litres per truck per period |
| Charge run period | Calendar month, aligned to the existing cutoff calendar |
| Revenue cost code for charges | The account's first cost code, as `RevenuePoster` already does |
| Truck identification | `equipment.category` matches `config('logistics.truck_categories')`, default `['truck']` |
| Roadworthy | `EquipmentStatus::Available`. `Deployed`, `UnderRepair`, `Returned` and `Disposed` are all excluded from trip capacity |
| Picking productivity denominator | Elapsed task time, not paid shift hours |
