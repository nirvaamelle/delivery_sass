<?php

/*
|--------------------------------------------------------------------------
| Shared billing fixtures
|--------------------------------------------------------------------------
|
| The billing chain is as deep as the procurement one: by the time a test needs
| an invoice it needs a signed contract, a schedule, a milestone with its whole
| document set on file, a measured accomplishment, a joint survey signed by both
| sides, a submitted billing and an approved one.
|
| These live here rather than in whichever suite defined them first, for the
| reason the procurement fixtures give and this build has now relearned three
| times: a helper declared in a test file is global to every other test file,
| so a suite only passes when run alongside its neighbours — and the second
| suite to want the name dies with a fatal redeclare rather than a failure.
|
| They go through the real services throughout. A fixture that inserted rows
| would build a chain the application itself would have refused.
|
*/

use App\Domain\Billing\AccomplishmentService;
use App\Domain\Billing\BillingScheduleService;
use App\Domain\Billing\BillingService;
use App\Domain\Billing\CollectionService;
use App\Domain\Billing\RetentionService;
use App\Domain\Contracts\ContractStatus;
use App\Domain\Posting\RevenuePoster;
use App\Models\Billing;
use App\Models\BillingMilestone;
use App\Models\Contract;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;

function billings(): BillingService
{
    return app(BillingService::class);
}

function schedules(): BillingScheduleService
{
    return app(BillingScheduleService::class);
}

function accomplishments(): AccomplishmentService
{
    return app(AccomplishmentService::class);
}

function collections(): CollectionService
{
    return app(CollectionService::class);
}

/**
 * A signed contract with a milestone schedule.
 *
 * @return array{0: Project, 1: Contract}
 */
function contractedProject(string $contractSum = '48500000.0000'): array
{
    $project = Project::factory()->create();

    $contract = Contract::factory()->for($project)->create([
        'status' => ContractStatus::Signed,
        'contract_sum' => $contractSum,
        'retention_rate' => '10.00',
    ]);

    // A cost code in the same organization. PLAN.md section 1 asks every ledger
    // row to carry one, and revenue is a ledger row like any other — a project
    // with no codes at all cannot post anything, which is a real refusal rather
    // than a fixture inconvenience.
    CostCode::factory()->create([
        'organization_id' => $project->organization_id,
        'code' => '01.00.000',
        'name' => 'Contract revenue',
    ]);

    return [$project, $contract->fresh()];
}

/**
 * A signed contract on its own, for suites that only need the schedule.
 */
function signedContract(string $contractSum = '48500000.0000'): Contract
{
    [, $contract] = contractedProject($contractSum);

    return $contract;
}

/**
 * The 30% downpayment milestone with its whole document set on file.
 *
 * @return array{0: Project, 1: Contract, 2: BillingMilestone}
 */
function billableDownpayment(): array
{
    [$project, $contract] = contractedProject();

    $schedule = schedules()->createFor($contract);
    $milestone = $schedule->milestones()->where('code', 'downpayment_30')->sole();

    $user = User::factory()->create();

    foreach (['billing_form', 'signed_contract', 'invoice_request'] as $key) {
        schedules()->attachDocument($milestone->fresh(), $key, strtoupper($key).'-1', $user);
    }

    return [$project, $contract, $milestone->fresh()];
}

/**
 * The 50% progress milestone: documents on file, and the site verified at
 * `$verified` percent by a joint survey both sides have signed.
 *
 * @return array{0: Project, 1: BillingMilestone}
 */
function billableProgress(string $verified = '52.00'): array
{
    [$project, $contract] = contractedProject();

    $schedule = schedules()->createFor($contract);
    $milestone = $schedule->milestones()->where('code', 'progress_50')->sole();

    $user = User::factory()->create();

    foreach (['accomplishment_report', 'joint_survey', 'photos'] as $key) {
        schedules()->attachDocument($milestone->fresh(), $key, strtoupper($key).'-1', $user);
    }

    verifiedAccomplishment($project, $verified, $user);

    return [$project, $milestone->fresh()];
}

/**
 * A measurement the client has actually signed.
 */
function verifiedAccomplishment(Project $project, string $percentage, ?User $user = null): void
{
    $user = $user ?? User::factory()->create();

    $accomplishment = accomplishments()->record(
        $project,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
        $percentage,
        $user,
    );

    $survey = accomplishments()->openSurvey($accomplishment, Carbon::parse('2026-06-01'), 'E. Cruz', 'M. Reyes');

    accomplishments()->signAsContractor($survey, $user);
    accomplishments()->signAsClient($survey->fresh(), 'E. Cruz');
}

/**
 * An approved downpayment billing, ready to invoice.
 */
function approvedBilling(): Billing
{
    [$project, $contract, $milestone] = billableDownpayment();

    $billing = billings()->submit($milestone, [
        ['description' => '30% downpayment per contract', 'amount' => '14550000.0000', 'line_key' => 'downpayment'],
    ]);

    return billings()->approve($billing, Carbon::parse('2026-05-20'));
}

function retention(): RetentionService
{
    return app(RetentionService::class);
}

function revenue(): RevenuePoster
{
    return app(RevenuePoster::class);
}
