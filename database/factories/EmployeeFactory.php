<?php

namespace Database\Factories;

use App\Domain\Hris\EmploymentStatus;
use App\Models\Employee;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    protected $model = Employee::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'employee_number' => strtoupper($this->faker->unique()->bothify('EMP-####')),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'position' => 'Carpenter',
            'date_hired' => '2026-01-15',
            'status' => EmploymentStatus::Active,
        ];
    }
}
