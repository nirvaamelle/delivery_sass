<?php

namespace Database\Factories;

use App\Domain\Budgets\BudgetStatus;
use App\Models\Budget;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Budget>
 */
class BudgetFactory extends Factory
{
    protected $model = Budget::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => 'Original budget',
            'status' => BudgetStatus::Draft,
        ];
    }

    public function open(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BudgetStatus::Open,
        ]);
    }
}
