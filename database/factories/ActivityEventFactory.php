<?php

namespace Database\Factories;

use App\Models\ActivityEvent;
use App\Models\Organisation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityEvent>
 */
class ActivityEventFactory extends Factory
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
            'type' => 'test.event',
            'payload' => [],
            // The contract's next position, as ActivityLog would assign it.
            'position' => fn (array $attributes): int => (int) ActivityEvent::query()
                ->where('contract_id', $attributes['contract_id'] ?? null)
                ->max('position') + 1,
        ];
    }
}
