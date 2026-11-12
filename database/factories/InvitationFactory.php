<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\Invitation;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'organisation_id' => fn (array $attributes) => Contract::query()->findOrFail((int) $attributes['contract_id'])->project->organisation_id,
            'party_id' => fn (array $attributes) => Party::factory()->create(['organisation_id' => $attributes['organisation_id']])->id,
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDays(7),
        ];
    }
}
