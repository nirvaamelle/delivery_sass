<?php

namespace Database\Factories;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\CostCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BudgetLine>
 */
class BudgetLineFactory extends Factory
{
    protected $model = BudgetLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'budget_id' => Budget::factory(),
            'cost_code_id' => CostCode::factory(),
            // A string, not a float — money never becomes a float, not even in
            // a factory default.
            'amount' => '100000.0000',
        ];
    }
}
