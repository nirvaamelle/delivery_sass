<?php

namespace Database\Factories;

use App\Domain\Vendors\VendorStatus;
use App\Models\Organization;
use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('VEN-####')),
            'name' => $this->faker->company(),
            'status' => VendorStatus::Pending,
        ];
    }
}
