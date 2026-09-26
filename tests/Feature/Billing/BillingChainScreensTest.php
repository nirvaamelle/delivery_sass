<?php

use App\Domain\Billing\AccomplishmentStatus;
use App\Domain\Billing\ArAgingService;
use App\Domain\Billing\BillingStatus;
use App\Domain\Billing\CollectionService;
use App\Filament\Resources\Accomplishments\Pages\ListAccomplishments;
use App\Filament\Resources\ArEscalations\Pages\ListArEscalations;
use App\Filament\Resources\Billings\Pages\ListBillings;
use App\Filament\Resources\SalesInvoices\Pages\ListSalesInvoices;
use App\Models\Accomplishment;
use App\Models\ArEscalation;
use App\Models\Billing;
use App\Models\Project;
use App\Models\SalesInvoice;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
|--------------------------------------------------------------------------
| The billing chain on screen — OS-02b
|--------------------------------------------------------------------------
|
| The whole acquisition-to-cash chain was list-only: measure, survey, bill,
| approve or return, invoice, collect, chase. Every service was built and tested
| and none of it could be started from the panel.
|
| The refusals matter more than the happy paths here, and each reaches the
| screen: a billing without its milestone documents (F4), a billing ahead of
| what the client has verified, a collection larger than the invoice.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-06-02 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

/*
|--------------------------------------------------------------------------
| Measurement and the joint survey
|--------------------------------------------------------------------------
*/

it('records an accomplishment from the screen', function () {
    actingAs(userWithRole('finance-manager'));
    $project = Project::factory()->create();

    Livewire::test(ListAccomplishments::class)
        ->callAction(TestAction::make('recordAccomplishment'), data: [
            'project_id' => $project->getKey(),
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'percentage_complete' => '52.00',
        ]);

    expect((string) Accomplishment::query()->sole()->percentage_complete)->toBe('52.00');
});

it('refuses a measurement that falls with no reason, on screen', function () {
    // A re-measurement is legitimate; a percentage that quietly drops is the one
    // nobody can account for at close-out.
    actingAs(userWithRole('finance-manager'));
    $project = Project::factory()->create();

    Livewire::test(ListAccomplishments::class)
        ->callAction(TestAction::make('recordAccomplishment'), data: [
            'project_id' => $project->getKey(),
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
            'percentage_complete' => '52.00',
        ]);

    Livewire::test(ListAccomplishments::class)
        ->callAction(TestAction::make('recordAccomplishment'), data: [
            'project_id' => $project->getKey(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'percentage_complete' => '40.00',
        ])
        ->assertNotified();

    expect(Accomplishment::query()->count())->toBe(1);
});

it('walks a joint survey through both signatures, verifying the measurement', function () {
    actingAs(userWithRole('finance-manager'));
    $project = Project::factory()->create();
    $accomplishment = accomplishments()->record($project, Carbon::parse('2026-05-01'), Carbon::parse('2026-05-31'), '52.00');

    Livewire::test(ListAccomplishments::class)
        ->callAction(TestAction::make('openSurvey')->table($accomplishment), data: [
            'surveyed_on' => '2026-06-01',
            'client_representative' => 'E. Cruz',
            'contractor_representative' => 'M. Reyes',
        ]);

    Livewire::test(ListAccomplishments::class)
        ->callAction(TestAction::make('signAsContractor')->table($accomplishment->fresh()));

    Livewire::test(ListAccomplishments::class)
        ->callAction(TestAction::make('signAsClient')->table($accomplishment->fresh()), data: ['signatory' => 'E. Cruz']);

    expect($accomplishment->fresh()->status)->toBe(AccomplishmentStatus::Verified);
});

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
*/

it('submits a billing against a milestone', function () {
    actingAs(userWithRole('finance-manager'));
    [$project, $contract, $milestone] = billableDownpayment();

    Livewire::test(ListBillings::class)
        ->callAction(TestAction::make('submitBilling'), data: [
            'billing_milestone_id' => $milestone->getKey(),
            'lines' => [
                ['line_key' => 'downpayment', 'description' => '30% downpayment per contract', 'amount' => '14550000.0000'],
            ],
        ]);

    expect(Billing::query()->sole()->status)->toBe(BillingStatus::Submitted);
});

it('shows the missing milestone documents on screen, and bills nothing', function () {
    // F4. Every missing document at once, so the QS makes one trip.
    actingAs(userWithRole('finance-manager'));
    [$project, $contract] = contractedProject();
    $milestone = schedules()->createFor($contract)->milestones()->where('code', 'downpayment_30')->sole();

    Livewire::test(ListBillings::class)
        ->callAction(TestAction::make('submitBilling'), data: [
            'billing_milestone_id' => $milestone->getKey(),
            'lines' => [
                ['line_key' => 'downpayment', 'description' => '30% downpayment', 'amount' => '14550000.0000'],
            ],
        ])
        ->assertNotified();

    expect(Billing::query()->count())->toBe(0);
});

it('approves a billing from the register', function () {
    actingAs(userWithRole('finance-manager'));
    [$project, $contract, $milestone] = billableDownpayment();
    $billing = billings()->submit($milestone, [
        ['line_key' => 'downpayment', 'description' => '30% downpayment', 'amount' => '14550000.0000'],
    ]);

    Livewire::test(ListBillings::class)
        ->callAction(TestAction::make('approveBilling')->table($billing), data: [
            'evaluated_at' => '2026-05-20',
            'remarks' => 'Accepted in full.',
        ]);

    expect($billing->fresh()->status)->toBe(BillingStatus::Approved);
});

it('returns a billing with a reason and blocks the deducted line', function () {
    actingAs(userWithRole('finance-manager'));
    [$project, $contract, $milestone] = billableDownpayment();
    $billing = billings()->submit($milestone, [
        ['line_key' => 'downpayment', 'description' => '30% downpayment', 'amount' => '14550000.0000'],
    ]);

    Livewire::test(ListBillings::class)
        ->callAction(TestAction::make('returnBilling')->table($billing), data: [
            'evaluated_at' => '2026-05-20',
            'reason' => 'Quantities do not match the joint survey.',
            'deductions' => [
                ['line_key' => 'downpayment', 'reason' => 'Client disputes the basis.'],
            ],
        ]);

    expect($billing->fresh()->status)->toBe(BillingStatus::Returned)
        ->and(billings()->blockedLineKeys($project))->toContain('downpayment');
});

/*
|--------------------------------------------------------------------------
| Invoice and collection
|--------------------------------------------------------------------------
*/

it('invoices an approved billing and records a collection', function () {
    actingAs(userWithRole('finance-manager'));
    $billing = approvedBilling();

    Livewire::test(ListSalesInvoices::class)
        ->callAction(TestAction::make('raiseInvoice'), data: [
            'billing_id' => $billing->getKey(),
            'issued_on' => '2026-05-21',
        ]);

    $invoice = SalesInvoice::query()->sole();
    $collectible = (string) $invoice->collectible_amount;

    Livewire::test(ListSalesInvoices::class)
        ->callAction(TestAction::make('recordCollection')->table($invoice), data: [
            'amount' => $collectible,
            'received_on' => '2026-06-01',
            'payment_reference' => 'BPI-778812',
        ]);

    expect(app(CollectionService::class)->outstandingFor($invoice->fresh()))->toBe('0.0000');
});

it('refuses a collection larger than the invoice, on screen', function () {
    // Over-collection is almost never generosity: it is a payment belonging to
    // another invoice, and it hides there until a reconciliation finds it.
    actingAs(userWithRole('finance-manager'));
    $billing = approvedBilling();
    $invoice = app(CollectionService::class)->invoice($billing, Carbon::parse('2026-05-21'));

    Livewire::test(ListSalesInvoices::class)
        ->callAction(TestAction::make('recordCollection')->table($invoice), data: [
            'amount' => bcadd((string) $invoice->collectible_amount, '1000.0000', 4),
            'received_on' => '2026-06-01',
        ])
        ->assertNotified();

    expect(app(CollectionService::class)->collectedFor($invoice->fresh()))->toBe('0.0000');
});

/*
|--------------------------------------------------------------------------
| Chasing what is owed
|--------------------------------------------------------------------------
*/

it('acknowledges an AR escalation with what was done about it', function () {
    actingAs(userWithRole('finance-manager'));
    $billing = approvedBilling();
    app(CollectionService::class)->invoice($billing, Carbon::parse('2026-01-02'));

    // Far enough past due for the sweep to raise it.
    Carbon::setTestNow('2026-06-02 09:00:00');
    app(ArAgingService::class)->sweep();

    $escalation = ArEscalation::query()->firstOrFail();

    Livewire::test(ListArEscalations::class)
        ->callAction(TestAction::make('acknowledgeEscalation')->table($escalation), data: [
            'resolution' => 'Client confirmed payment for the 15th.',
        ]);

    expect($escalation->fresh()->acknowledged_at)->not->toBeNull()
        ->and($escalation->fresh()->resolution)->toBe('Client confirmed payment for the 15th.');
});
