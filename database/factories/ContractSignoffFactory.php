<?php

namespace Database\Factories;

use App\Models\Contract;
use App\Models\ContractSignoff;
use App\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractSignoff>
 */
class ContractSignoffFactory extends Factory
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
            'party_id' => Party::factory(),
            'fingerprint' => hash('sha256', fake()->uuid()),
        ];
    }
}
