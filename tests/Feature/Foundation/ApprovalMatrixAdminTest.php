<?php

use App\Filament\Resources\ApprovalMatrices\ApprovalMatrixResource;
use App\Filament\Resources\ApprovalMatrices\Pages\CreateApprovalMatrix;
use App\Filament\Resources\ApprovalMatrices\Pages\EditApprovalMatrix;
use App\Filament\Resources\ApprovalMatrices\Pages\ListApprovalMatrices;
use App\Models\ApprovalMatrix;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Approval matrix admin — P0-14
|--------------------------------------------------------------------------
|
| The first screen in the build, and the one that proves the architectural rule
| from PLAN.md §3 holds in practice: domain logic lives in app/Domain, never in
| a Filament resource. The screen does not write approval_matrix rows. It calls
| ApprovalRouter::defineTier() and translates a domain refusal into a form
| error, which is the UI layer's actual job.
|
| The test that matters most here is the overlapping-band one. If the admin
| screen could write a matrix the service would reject, the overlap guard would
| protect the seeder and the tests while the one path a human actually uses
| walked straight past it.
|
*/

beforeEach(function () {
    // Screens are authorised by role (config/access.php). This suite tests what
    // the screens show, so it signs in as the administrator, who sees them all.
    actingAs(panelUser());
});

it('renders the approval matrix list', function () {
    get(ApprovalMatrixResource::getUrl('index'))->assertSuccessful();
});

it('lists the tiers already defined', function () {
    $matrix = ApprovalMatrix::query()->create([
        'document_type' => 'purchase_order',
        'tier' => 1,
        'min_amount' => '0.0000',
        'max_amount' => '50000.0000',
        'approver_roles' => ['project-manager'],
        'required_documents' => ['canvass'],
    ]);

    Livewire::test(ListApprovalMatrices::class)
        ->assertCanSeeTableRecords([$matrix]);
});

it('renders the create form', function () {
    get(ApprovalMatrixResource::getUrl('create'))->assertSuccessful();
});

it('defines a tier through the domain service', function () {
    Livewire::test(CreateApprovalMatrix::class)
        ->fillForm([
            'document_type' => 'purchase_order',
            'tier' => 1,
            'min_amount' => '0.0000',
            'max_amount' => '50000.0000',
            'approver_roles' => ['project-manager'],
            'required_documents' => ['canvass'],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(ApprovalMatrix::query()->where('document_type', 'purchase_order')->count())->toBe(1);
});

it('refuses a tier whose band overlaps one already defined', function () {
    // The domain guard, reaching the screen. An admin form able to write an
    // overlapping band would make the matrix ambiguous through the one path a
    // human actually uses.
    ApprovalMatrix::query()->create([
        'document_type' => 'purchase_order',
        'tier' => 1,
        'min_amount' => '0.0000',
        'max_amount' => '50000.0000',
        'approver_roles' => ['project-manager'],
        'required_documents' => [],
    ]);

    Livewire::test(CreateApprovalMatrix::class)
        ->fillForm([
            'document_type' => 'purchase_order',
            'tier' => 2,
            'min_amount' => '40000.0000',
            'max_amount' => '500000.0000',
            'approver_roles' => ['procurement-head'],
            'required_documents' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['min_amount']);

    expect(ApprovalMatrix::query()->count())->toBe(1);
});

it('refuses an edit that would make an existing tier overlap', function () {
    ApprovalMatrix::query()->create([
        'document_type' => 'purchase_order',
        'tier' => 1,
        'min_amount' => '0.0000',
        'max_amount' => '50000.0000',
        'approver_roles' => ['project-manager'],
        'required_documents' => [],
    ]);

    $second = ApprovalMatrix::query()->create([
        'document_type' => 'purchase_order',
        'tier' => 2,
        'min_amount' => '50000.0001',
        'max_amount' => '500000.0000',
        'approver_roles' => ['procurement-head'],
        'required_documents' => [],
    ]);

    Livewire::test(EditApprovalMatrix::class, ['record' => $second->getRouteKey()])
        ->fillForm(['min_amount' => '25000.0000'])
        ->call('save')
        ->assertHasFormErrors(['min_amount']);

    expect($second->fresh()->min_amount)->toBe('50000.0001');
});

it('requires at least one approver role', function () {
    // A tier with nobody on it routes a document into a void: the approval sits
    // pending forever and no inbox ever shows it.
    Livewire::test(CreateApprovalMatrix::class)
        ->fillForm([
            'document_type' => 'purchase_order',
            'tier' => 1,
            'min_amount' => '0.0000',
            'max_amount' => '50000.0000',
            'approver_roles' => [],
            'required_documents' => [],
        ])
        ->call('create')
        ->assertHasFormErrors(['approver_roles']);
});
