<?php

namespace Database\Factories;

use App\Domain\Requisitions\RequisitionStatus;
use App\Models\CostCode;
use App\Models\Project;
use App\Models\PurchaseRequisition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseRequisition>
 */
class PurchaseRequisitionFactory extends Factory
{
    protected $model = PurchaseRequisition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            // Factories bypass the Numbering service deliberately — a test
            // fixture must not consume a real sequence number, or the gapless
            // guarantee becomes a test-ordering artefact.
            'number' => 'PR-2026-'.str_pad((string) $this->faker->unique()->numberBetween(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => RequisitionStatus::Draft,
            'total_amount' => '25000.0000',
        ];
    }

    /**
     * A requisition always has at least one costed line.
     *
     * PLAN.md section 5's first control is "no PR without a cost code", so a
     * requisition with no lines is not a lesser fixture - it is a document the
     * system is supposed to refuse. Every downstream document inherits the cost
     * code from here, and P1-06's non-nullable `purchase_order_lines.cost_code_id`
     * is what caught the omission.
     */
    public function configure(): self
    {
        return $this->afterCreating(function (PurchaseRequisition $requisition): void {
            if ($requisition->lines()->exists()) {
                return;
            }

            // Past the row scope: a factory builds fixture data and must not
            // depend on the assignments of whoever happens to be authenticated.
            // Without this, creating a requisition while signed in as a scoped
            // user fails to find the project it has just created.
            $project = Project::withoutProjectScope(
                fn (): Project => Project::query()->findOrFail($requisition->project_id),
            );

            $costCode = CostCode::query()
                ->where('organization_id', $project->organization_id)
                ->first()
                ?? CostCode::factory()->create(['organization_id' => $project->organization_id]);

            $requisition->lines()->create([
                'cost_code_id' => $costCode->getKey(),
                'description' => 'Fixture line',
                'amount' => $requisition->total_amount,
            ]);
        });
    }

    public function approved(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => RequisitionStatus::Approved,
        ]);
    }
}
