<?php

namespace Database\Factories;

use App\Domain\Projects\ProjectPhase;
use App\Domain\Projects\ProjectStatus;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'code' => strtoupper($this->faker->unique()->bothify('PRJ-2026-###')),
            'name' => ucfirst($this->faker->words(3, true)).' Project',
            'client_name' => $this->faker->company(),
            'phase' => ProjectPhase::ProjectAcquisition,
            'status' => ProjectStatus::Active,
        ];
    }
}
