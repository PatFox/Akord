<?php

namespace Database\Factories;

use App\Enums\PartyType;
use App\Models\Organisation;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organisation_id' => Organisation::factory(),
            'name' => fake()->name(),
            'type' => PartyType::Individual,
        ];
    }

    public function team(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => ucfirst(fake()->word()).' team',
            'type' => PartyType::Team,
        ]);
    }

    public function agent(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => fake()->word().' agent',
            'type' => PartyType::Agent,
        ]);
    }
}
