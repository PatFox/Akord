<?php

namespace Database\Factories;

use App\Models\AgentClaimCode;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentClaimCode>
 */
class AgentClaimCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party_id' => Party::factory()->agent(),
            'code_hash' => hash('sha256', Str::random(40)),
            'expires_at' => now()->addDay(),
        ];
    }
}
