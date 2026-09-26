<?php

use App\Domain\Budgets\BudgetStatus;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Gates\GateFailedException;
use App\Domain\Gates\Gatekeeper;
use App\Domain\Projects\ProjectTransition;
use App\Models\Budget;
use App\Models\Contract;
use App\Models\Project;

/*
|--------------------------------------------------------------------------
| Project gate — P0-11, closes F14
|--------------------------------------------------------------------------
|
| "No PR against a project without a signed contract AND an opened budget."
|
| This is the first real policy registered in the Gatekeeper, and it is the
| gate the Phase 0 exit criterion turns on. It is declared against the PROJECT,
| not against the requisition, because the requisition does not exist yet —
| Phase 1 calls assert($project, ...) before it creates one. Attaching the rule
| to the thing it is really about also means every future document that spends
| project money can reuse it unchanged.
|
| Both halves matter independently. A signed contract with no budget means
| there is no cost code to charge and no availability to check. An opened
| budget with no signed contract means the company is committing to spend
| against revenue it has not contractually secured.
|
*/

function gate(): Gatekeeper
{
    return app(Gatekeeper::class);
}

function projectWith(?ContractStatus $contract, ?BudgetStatus $budget): Project
{
    $project = Project::factory()->create();

    if ($contract !== null) {
        Contract::factory()->for($project)->create(['status' => $contract]);
    }

    if ($budget !== null) {
        Budget::factory()->for($project)->create(['status' => $budget]);
    }

    return $project;
}

it('allows a requisition when the contract is signed and the budget is open', function () {
    $project = projectWith(ContractStatus::Signed, BudgetStatus::Open);

    gate()->assert($project, ProjectTransition::RaisePurchaseRequisition->value);

    expect(gate()->failuresFor($project, ProjectTransition::RaisePurchaseRequisition->value))
        ->toBeEmpty();
});

it('blocks a requisition when the project has no contract at all', function () {
    $project = projectWith(null, BudgetStatus::Open);

    expect(fn () => gate()->assert($project, ProjectTransition::RaisePurchaseRequisition->value))
        ->toThrow(GateFailedException::class);
});

it('blocks a requisition when the contract is drafted but not signed', function () {
    // A contract in the drawer is not a contract. This is the case the finding
    // is actually about — the paperwork exists, so it looks done.
    $project = projectWith(ContractStatus::Draft, BudgetStatus::Open);

    expect(fn () => gate()->assert($project, ProjectTransition::RaisePurchaseRequisition->value))
        ->toThrow(GateFailedException::class);
});

it('blocks a requisition when the budget is still a draft', function () {
    $project = projectWith(ContractStatus::Signed, BudgetStatus::Draft);

    expect(fn () => gate()->assert($project, ProjectTransition::RaisePurchaseRequisition->value))
        ->toThrow(GateFailedException::class);
});

it('blocks a requisition when the project has no budget at all', function () {
    $project = projectWith(ContractStatus::Signed, null);

    expect(fn () => gate()->assert($project, ProjectTransition::RaisePurchaseRequisition->value))
        ->toThrow(GateFailedException::class);
});

it('reports both failures when neither the contract nor the budget is ready', function () {
    // The Gatekeeper's all-failures contract, on a real gate: raising a PR
    // against an unstarted project should tell you everything that is missing,
    // not send you round twice.
    $project = projectWith(ContractStatus::Draft, BudgetStatus::Draft);

    $failures = gate()->failuresFor($project, ProjectTransition::RaisePurchaseRequisition->value);

    expect($failures)->toHaveCount(2)
        ->and(array_keys($failures))->toBe([
            'project-has-signed-contract',
            'project-has-open-budget',
        ]);
});

it('does not accept another project\'s signed contract', function () {
    // Both preconditions must be scoped to the project being gated, or the
    // first signed contract in the database would unlock every project.
    $other = Project::factory()->create();
    Contract::factory()->for($other)->create(['status' => ContractStatus::Signed]);

    $project = Project::factory()->create();
    Budget::factory()->for($project)->create(['status' => BudgetStatus::Open]);

    $failures = gate()->failuresFor($project, ProjectTransition::RaisePurchaseRequisition->value);

    expect(array_keys($failures))->toBe(['project-has-signed-contract']);
});

it('holds the contract sum as exact decimal money', function () {
    $contract = Contract::factory()->create(['contract_sum' => '98765432109876.5432']);

    $sum = $contract->fresh()->contract_sum;

    expectMoney($sum);
    expect($sum)->toBe('98765432109876.5432');
});
