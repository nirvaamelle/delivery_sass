<?php

namespace Database\Factories;

use App\Models\CostCode;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CostCode>
 */
class CostCodeFactory extends Factory
{
    protected $model = CostCode::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'parent_id' => null,
            'code' => $this->faker->unique()->numerify('##.##.###'),
            'name' => $this->faker->words(3, true),
        ];
    }
}
