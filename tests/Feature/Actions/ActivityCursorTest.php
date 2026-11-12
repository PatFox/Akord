<?php

use App\Models\ActivityEvent;
use App\Support\ActivitySequencer;
use Tests\Support\Scenario;

test('writes are sequenced after commit', function () {
    $s = Scenario::make();
    $s->step();

    expect(ActivityEvent::query()->whereNull('seq')->count())->toBe(0)
        ->and(ActivityEvent::query()->after(0)->pluck('type')->all())->toBe(['step.created']);
});

test('an event that commits late with a lower id is still delivered after the cursor', function () {
    $s = Scenario::make();
    $attributes = ['organisation_id' => $s->organisation->id, 'contract_id' => $s->contract->id];

    // Push the id counter ahead, then let a normal write commit and be read.
    ActivityEvent::factory()->create(['id' => 500, ...$attributes]);
    $s->step();
    $cursor = (int) ActivityEvent::query()->max('seq');
    $highestIdSeen = (int) ActivityEvent::query()->max('id');

    // A transaction that took id 100 earlier commits only now.
    ActivityEvent::factory()->create(['id' => 100, ...$attributes]);
    ActivitySequencer::run();

    expect(ActivityEvent::query()->where('id', '>', $highestIdSeen)->count())->toBe(0)
        ->and(ActivityEvent::query()->after($cursor)->pluck('id')->all())->toBe([100]);
});
