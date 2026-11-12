<?php

namespace Database\Factories;

use App\Models\Phase;
use App\Models\Step;
use App\Models\StepRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Step>
 */
class StepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'phase_id' => Phase::factory(),
            'position' => 1,
        ];
    }

    /**
     * Every step starts with revision 1 as its current revision.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Step $step): void {
            if ($step->current_revision_id !== null) {
                return;
            }

            $revision = StepRevision::factory()->for($step)->create();
            $step->update(['current_revision_id' => $revision->id]);
        });
    }
}
