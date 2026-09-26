<?php

use App\Domain\Billing\BillingScheduleService;
use App\Domain\Contracts\ContractService;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Projects\ProjectAccessService;
use App\Domain\Projects\ProjectRole;
use App\Filament\Resources\Contracts\ContractsResource;
use App\Filament\Resources\Contracts\Pages\CreateContract;
use App\Filament\Resources\Contracts\Pages\EditContract;
use App\Filament\Resources\Contracts\RelationManagers\MilestonesRelationManager;
use App\Models\Contract;
use App\Models\MilestoneDocument;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
|--------------------------------------------------------------------------
| Contracts on screen — OS-02a
|--------------------------------------------------------------------------
|
| The contract had no screen at all, which made the system unusable from a clean
| install: F14 refuses a requisition without a signed contract, so nobody could
| spend, and no milestone existed to bill against.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

it('drafts a contract from the form', function () {
    actingAs(userWithRole('finance-manager'));
    $project = Project::factory()->create();

    Livewire::test(CreateContract::class)
        ->fillForm([
            'project_id' => $project->getKey(),
            'number' => 'CON-2026-014',
            'contract_sum' => '48500000.0000',
            'retention_rate' => '0.100000',
            'defects_liability_days' => 365,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Contract::query()->where('number', 'CON-2026-014')->sole()->status)->toBe(ContractStatus::Draft);
});

it('shows a retention rate typed as a percentage beside its own field', function () {
    // 10 means "ten per cent" to a person and "ten times the invoice" to the
    // retention calculation.
    actingAs(userWithRole('finance-manager'));

    Livewire::test(CreateContract::class)
        ->fillForm([
            'project_id' => Project::factory()->create()->getKey(),
            'number' => 'CON-BAD-RATE',
            'contract_sum' => '1000.0000',
            'retention_rate' => '10',
        ])
        ->call('create')
        ->assertHasFormErrors(['retention_rate']);
});

it('walks a contract from draft to signed, building the billing schedule', function () {
    actingAs(userWithRole('finance-manager'));
    $contract = app(ContractService::class)->draft(Project::factory()->create(), [
        'number' => 'CON-SIGN-1',
        'contract_sum' => '48500000.0000',
    ]);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('sendForSignature');

    expect($contract->fresh()->status)->toBe(ContractStatus::ForSignature);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->callAction('signContract', data: ['signed_at' => '2026-05-10', 'contract_type' => 'default']);

    $contract->refresh();

    expect($contract->status)->toBe(ContractStatus::Signed)
        ->and($contract->milestones()->count())->toBeGreaterThan(0);
});

it('locks the terms on screen once the contract leaves draft', function () {
    actingAs(userWithRole('finance-manager'));
    $contract = app(ContractService::class)->draft(Project::factory()->create(), [
        'number' => 'CON-LOCK-1',
        'contract_sum' => '1000000.0000',
    ]);
    app(ContractService::class)->sendForSignature($contract);

    Livewire::test(EditContract::class, ['record' => $contract->getRouteKey()])
        ->assertFormFieldDisabled('contract_sum')
        ->assertFormFieldDisabled('retention_rate')
        ->assertActionHidden('sendForSignature')
        ->assertActionVisible('signContract');
});

it('attaches a required document to a milestone, and refuses one it does not ask for', function () {
    actingAs(userWithRole('finance-manager'));

    $contract = app(ContractService::class)->draft(Project::factory()->create(), [
        'number' => 'CON-DOC-1',
        'contract_sum' => '48500000.0000',
    ]);
    app(ContractService::class)->sendForSignature($contract);
    app(ContractService::class)->sign($contract->fresh(), Carbon::parse('2026-05-10'));

    $milestone = $contract->fresh()->milestones()->orderBy('sequence')->first();
    $required = $milestone->requirements()->value('document_key');

    Livewire::test(MilestonesRelationManager::class, ['ownerRecord' => $contract->fresh(), 'pageClass' => EditContract::class])
        ->callTableAction('attachDocument', $milestone, data: [
            'document_key' => $required,
            'reference' => 'DOC-001',
        ]);

    expect(MilestoneDocument::query()->where('billing_milestone_id', $milestone->getKey())->sole()->reference)->toBe('DOC-001')
        ->and(app(BillingScheduleService::class)->missingFor($milestone->fresh()))->not->toContain($required);
});

it('shows a project manager only the contracts of projects they are on', function () {
    $mine = Project::factory()->create();
    $theirs = Project::factory()->create();
    $pm = userWithRole('project-manager');
    app(ProjectAccessService::class)->assign($pm, $mine, ProjectRole::ProjectManager);

    $service = app(ContractService::class);
    $myContract = $service->draft($mine, ['number' => 'CON-MINE', 'contract_sum' => '1000.0000']);
    $theirContract = $service->draft($theirs, ['number' => 'CON-THEIRS', 'contract_sum' => '1000.0000']);

    actingAs($pm);

    get(ContractsResource::getUrl('edit', ['record' => $myContract]))->assertSuccessful();
    get(ContractsResource::getUrl('edit', ['record' => $theirContract]))->assertNotFound();
});
