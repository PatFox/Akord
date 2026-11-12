<?php

namespace Database\Factories;

use App\Models\Party;
use App\Models\Step;
use App\Models\StepRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StepRevision>
 */
class StepRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'step_id' => Step::factory(),
            'number' => fn (array $attributes) => (int) StepRevision::query()->where('step_id', $attributes['step_id'])->max('number') + 1,
            'title' => fake()->sentence(4),
            'description_md' => fake()->paragraph(),
            'author_party_id' => Party::factory(),
        ];
    }
}
