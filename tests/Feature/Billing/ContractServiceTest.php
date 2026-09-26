<?php

use App\Domain\Billing\BillingScheduleService;
use App\Domain\Contracts\ContractService;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Contracts\InvalidContractDetail;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Support\Carbon;

/*
|--------------------------------------------------------------------------
| The client contract
|--------------------------------------------------------------------------
|
| Contracts had no service and no screen — factories and seeders only — yet
| nothing else can start without one: F14 refuses a purchase requisition on a
| project with no SIGNED contract, and the billing schedule is built from the
| contract sum.
|
| The rules are about what the contract dates and computes elsewhere. Its sum,
| retention rate and defects liability period are fixed once it leaves draft,
| because milestone amounts, retention withheld on every invoice and the
| warranty period are all derived from them.
|
*/

beforeEach(fn () => Carbon::setTestNow('2026-05-14 09:00:00'));

afterEach(fn () => Carbon::setTestNow());

function contracts(): ContractService
{
    return app(ContractService::class);
}

function draftedContract(array $overrides = []): Contract
{
    return contracts()->draft(Project::factory()->create(), array_merge([
        'number' => 'CON-'.uniqid(),
        'contract_sum' => '48500000.0000',
        'retention_rate' => '0.100000',
        'defects_liability_days' => 365,
    ], $overrides));
}

it('drafts a contract against a project', function () {
    $contract = draftedContract(['number' => 'CON-2026-001']);

    expect($contract->number)->toBe('CON-2026-001')
        ->and($contract->status)->toBe(ContractStatus::Draft)
        ->and((string) $contract->contract_sum)->toBe('48500000.0000');
});

it('refuses a contract with no number or no sum', function (string $field) {
    $attributes = ['number' => 'CON-X', 'contract_sum' => '1000.0000'];
    $attributes[$field] = '';

    $refusal = null;
    try {
        contracts()->draft(Project::factory()->create(), $attributes);
    } catch (InvalidContractDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidContractDetail::class)
        ->and($refusal?->field)->toBe($field);
})->with(['number', 'contract_sum']);

it('refuses a duplicate contract number', function () {
    $existing = draftedContract();

    expect(fn () => draftedContract(['number' => $existing->number]))
        ->toThrow(InvalidContractDetail::class, 'already');
});

it('refuses a sum of zero and a retention rate typed as a percentage', function (string $field, string $value) {
    $refusal = null;
    try {
        draftedContract([$field => $value]);
    } catch (InvalidContractDetail $e) {
        $refusal = $e;
    }

    expect($refusal)->toBeInstanceOf(InvalidContractDetail::class)
        ->and($refusal?->field)->toBe($field);
})->with([
    'zero sum' => ['contract_sum', '0'],
    'negative sum' => ['contract_sum', '-1'],
    // 10 means "ten per cent" to a person and "ten times the invoice" to the
    // retention calculation.
    'percentage as rate' => ['retention_rate', '10'],
]);

it('signs a contract and builds its billing schedule in the same act', function () {
    // A signed contract with no schedule is a project that can spend but cannot
    // bill, and nothing would have told anybody until the first billing.
    $contract = draftedContract();
    contracts()->sendForSignature($contract);

    $signed = contracts()->sign($contract, Carbon::parse('2026-05-10'));

    expect($signed->status)->toBe(ContractStatus::Signed)
        ->and($signed->signed_at->toDateString())->toBe('2026-05-10')
        ->and(app(BillingScheduleService::class)->amountFor(
            $signed->schedule()->sole()->milestones()->orderBy('sequence')->first()
        ))->not->toBeNull();
});

it('refuses to sign a contract that never went out for signature', function () {
    expect(fn () => contracts()->sign(draftedContract(), Carbon::parse('2026-05-10')))
        ->toThrow(DomainException::class, 'goes out for signature');
});

it('leaves nothing signed when the contract type has no milestones', function () {
    // The signing and the schedule are one transaction: a contract signed
    // against an unknown type would be signed with nothing to bill.
    $contract = draftedContract();
    contracts()->sendForSignature($contract);

    expect(fn () => contracts()->sign($contract, Carbon::parse('2026-05-10'), 'no-such-type'))
        ->toThrow(DomainException::class);

    expect($contract->fresh()->status)->toBe(ContractStatus::ForSignature);
});

it('fixes the terms once the contract leaves draft', function () {
    $contract = draftedContract();
    contracts()->sendForSignature($contract);

    expect(fn () => contracts()->updateDetails($contract->fresh(), ['contract_sum' => '50000000.0000']))
        ->toThrow(DomainException::class, 'fixed once it leaves draft');
});

it('amends a draft, and refuses what is not its to change', function () {
    $contract = draftedContract();

    contracts()->updateDetails($contract, ['contract_sum' => '49000000.0000', 'ntp_date' => '2026-05-12']);

    expect((string) $contract->fresh()->contract_sum)->toBe('49000000.0000');

    expect(fn () => contracts()->updateDetails($contract->fresh(), ['project_id' => 999]))
        ->toThrow(InvalidContractDetail::class, 'project_id');

    expect(fn () => contracts()->updateDetails($contract->fresh(), ['status' => ContractStatus::Signed->value]))
        ->toThrow(InvalidContractDetail::class, 'status');
});

it('sends a contract back to draft with a reason, and refuses one without', function () {
    $contract = draftedContract();
    contracts()->sendForSignature($contract);

    expect(fn () => contracts()->returnToDraft($contract->fresh(), '  '))
        ->toThrow(DomainException::class, 'reason');

    expect(contracts()->returnToDraft($contract->fresh(), 'Client asked for a revised scope.')->status)
        ->toBe(ContractStatus::Draft);
});

it('terminates a contract with a reason, and refuses a second termination', function () {
    $contract = draftedContract();

    contracts()->terminate($contract, 'Client cancelled the project.');
    expect($contract->fresh()->status)->toBe(ContractStatus::Terminated);

    expect(fn () => contracts()->terminate($contract->fresh(), 'Again.'))
        ->toThrow(DomainException::class, 'already terminated');
});
