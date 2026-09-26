<?php

namespace Database\Factories;

use App\Domain\Equipment\DepreciationMethod;
use App\Domain\Equipment\EquipmentStatus;
use App\Domain\Equipment\Ownership;
use App\Models\Equipment;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    protected $model = Equipment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('EQ-####')),
            'description' => 'Hydraulic excavator',
            'category' => 'heavy_equipment',
            'ownership' => Ownership::Owned,
            'status' => EquipmentStatus::Available,
            'acquisition_cost' => '1200000.0000',
            'salvage_value' => '200000.0000',
            'acquired_on' => '2026-01-15',
            'useful_life_months' => 60,
            'depreciation_method' => DepreciationMethod::StraightLine,
        ];
    }
}
