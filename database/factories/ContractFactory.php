<?php

namespace Database\Factories;

use App\Domain\Contracts\ContractStatus;
use App\Models\Contract;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'number' => strtoupper($this->faker->unique()->bothify('CON-2026-###')),
            'status' => ContractStatus::Draft,
            'contract_sum' => '5000000.0000',
        ];
    }

    public function signed(): self
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ContractStatus::Signed,
            'signed_at' => now(),
        ]);
    }
}
