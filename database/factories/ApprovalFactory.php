<?php

namespace Database\Factories;

use App\Models\Approval;
use App\Models\Party;
use App\Models\Step;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Approval>
 */
class ApprovalFactory extends Factory
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
            'step_revision_id' => fn (array $attributes) => Step::query()->findOrFail((int) $attributes['step_id'])->current_revision_id,
            'party_id' => Party::factory(),
        ];
    }
}
